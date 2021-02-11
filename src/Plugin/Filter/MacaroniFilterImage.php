<?php

namespace Drupal\macaroni_filter_image\Plugin\Filter;

use Drupal\Core\Config\Config;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\Template\Attribute;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class MacaroniFilterImage
 * @package Drupal\macaroni_filter_image\Plugin\Filter
 *
 * @Filter(
 *   id = "macaroni_filter_image",
 *   title = @Translation("Macaroni Filter Image"),
 *   description = @Translation("Scales images to a given width"),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_REVERSIBLE,
 *   settings = {
 *     "image_locations" = { "local" = 1, "remote" = 0 },
 *     "image_scale" = "1024",
 *   }
 * )
 */
class MacaroniFilterImage extends FilterBase implements ContainerFactoryPluginInterface
{

  /**
   * ImageFactory instance.
   *
   * @var \Drupal\Core\Image\ImageFactory
   */
  protected $imageFactory;

  /**
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $systemFileConfig;

  /**
   * The stream wrapper manager.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected $streamWrapperManager;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;


  /**
   * MacaroniFilterImage constructor.
   * @param array $configuration
   * @param $plugin_id
   * @param $plugin_definition
   * @param ImageFactory $image_factory
   * @param Config $system_file_config
   * @param StreamWrapperManagerInterface $stream_wrapper_manager
   * @param FileSystemInterface $file_system
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ImageFactory $image_factory, Config $system_file_config, StreamWrapperManagerInterface $stream_wrapper_manager, FileSystemInterface $file_system)
  {

    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->imageFactory = $image_factory;
    $this->systemFileConfig = $system_file_config;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->fileSystem = $file_system;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
  {

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('image.factory'),
      $container->get('config.factory')->get('system.file'),
      $container->get('stream_wrapper_manager'),
      $container->get('file_system')
    );
  }

  /**
   * @param string $text
   * @param string $langcode
   * @return FilterProcessResult
   */
  public function process($text, $langcode)
  {
    $images = $this->getImages($this->settings, $text);

    $search = [];
    $replace = [];

    foreach ($images as $image) {
      // Copy remote images locally.
      if ($image['location'] == 'remote') {
        $local_file_path = 'scale/remote/' . md5(file_get_contents($image['local_path'])) . '-scaled' . '.' . $image['extension'];
        // Once Drupal 8.7.x is unsupported remove this IF statement.
        if (floatval(\Drupal::VERSION) >= 8.8) {
          $image['destination'] = $this->systemFileConfig->get('default_scheme') . '://' . $local_file_path;
        } else {
          $image['destination'] = file_default_scheme() . '://' . $local_file_path;
        }
      } else {
        $path_info = $this->getPathinfo($image['local_path']);
        // Once Drupal 8.7.x is unsupported remove this IF statement.
        if (floatval(\Drupal::VERSION) >= 8.8) {
          $local_file_dir = $this->streamWrapperManager->getTarget($path_info['dirname']);
        } else {
          $local_file_dir = file_uri_target($path_info['dirname']);
        }
        $local_file_path = 'scale/' . ($local_file_dir ? $local_file_dir . '/' : '') . $path_info['filename'] . '-scaled' . '.' . $path_info['extension'];
        $image['destination'] = $path_info['scheme'] . '://' . $local_file_path;
      }

      if (!file_exists($image['destination'])) {
        // Basic flood prevention of scaling.
        $scale_threshold = 10;
        $flood = \Drupal::flood();
        if (!$flood->isAllowed('macaroni_image_filter_scale', $scale_threshold, 120)) {
          $this->messenger->addMessage(t('Image scale threshold of @count per minute reached. Some images have not been scale. Resave the content to scale remaining images.', ['@count' => floor($scale_threshold / 2)]), 'error', FALSE);
          continue;
        }
        $flood->register('macaroni_image_filter_scale', 120);

        // Create the scale directory.
        $directory = dirname($image['destination']);
        $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);


        $copy = $this->fileSystem->copy($image['local_path'], $image['destination']);
        $res = $this->imageFactory->get($copy);

        if ($res) {
          // Image loaded successfully; scale.
          $expected_size = $this->settings['image_scale'];

          if ($image['actual_size'] > $expected_size) {

            $res->scale($expected_size);
            $res->save();
          }
        } else {
          // Image failed to load - type doesn't match extension or invalid; keep original file
          $handle = fopen($image['destination'], 'w');
          fwrite($handle, file_get_contents($image['local_path']));
          fclose($handle);
        }

        @chmod($image['destination'], 0664);
      }

      // Delete our temporary file if this is a remote image.
      $this->deleteTemp($image['location'], $image['local_path']);

      // Replace the existing image source with the scaled image.
      // Set the image we're currently updating in the callback function.
      $search[] = $image['img_tag'];
      $replace[] = $this->getImageTag($image, $this->settings);
    }

    return new FilterProcessResult(str_replace($search, $replace, $text));
  }

  /**
   * Parsing function to locate all images in a piece of text that need replacing.
   *
   * @param array $settings
   *   An array of settings that will be used to identify which images need
   *   updating. Includes the following:
   *
   *   - image_locations: An array of acceptable image locations. May contain any
   *     of the following values: "remote". Remote image will be downloaded and
   *     saved locally. This procedure is intensive as the images need to
   *     be retrieved to have their dimensions checked.
   * @param string $text
   *   The text to be updated with the new img src tags.
   *
   * @return array
   *   Return the images.
   */
  private function getImages(array $settings, $text)
  {
    $images = array();

    // Find all image tags, ensuring that they have a src.
    $matches = array();
    preg_match_all('/((<a [^>]*>)[ ]*)?(<img[^>]*?src[ ]*=[ ]*"([^"]+)"[^>]*>)/i', $text, $matches);

    // Loop through matches and find if replacements are necessary.
    // $matches[0]: All complete image tags and preceding anchors.
    // $matches[1]: The anchor tag of each match (if any).
    // $matches[2]: The anchor tag and trailing whitespace of each match (if any).
    // $matches[3]: The complete img tag.
    // $matches[4]: The src value of each match.
    foreach ($matches[0] as $key => $match) {
      $img_tag = $matches[3][$key];

      // Extract the query string (image style token) if any from the url.
      $src_query = parse_url($matches[4][$key], PHP_URL_QUERY);
      if ($src_query) {
        $src = substr($matches[4][$key], 0, -strlen($src_query) - 1);
      } else {
        $src = $matches[4][$key];
      }

      $image_size = NULL;
      $attributes = array();

      // Find attributes of this image tag.
      $attribute_matches = array();
      preg_match_all('/([\w\-]+)[ ]*=[ ]*"([^"]*)"/i', $img_tag, $attribute_matches);
      foreach ($attribute_matches[0] as $key => $match) {
        $attribute = $attribute_matches[1][$key];
        $attribute_value = $attribute_matches[2][$key];
        $attributes[$attribute] = $attribute_value;
      }

      // Height and width need to be matched specifically because they may come as
      // either an HTML attribute or as part of a style attribute. FCKeditor
      // specifically has a habit of using style tags instead of height and width.
      foreach (array('width', 'height') as $property) {
        $property_matches = array();
        preg_match_all('/[ \'";]' . $property . '[ ]*([=:])[ ]*"?([0-9]+)(%?)"?/i', $img_tag, $property_matches);

        // If this image uses percentage width or height, do not process it.
        if (in_array('%', $property_matches[3])) {
          $resize = FALSE;
          break;
        }

        // In the odd scenario there is both a style="width: xx" and a width="xx"
        // tag, base our calculations off the style tag, since that's what the
        // browser will display.
        $property_key = 0;
        $property_count = count($property_matches[1]);
        if ($property_count) {
          $property_key = array_search(':', $property_matches[1]);
        }
        $attributes[$property] = !empty($property_matches[2][$property_key]) ? $property_matches[2][$property_key] : '';
      }

      // Determine if this is a local or remote file.
      $base_path = base_path();
      $location = 'unknown';
      $host = \Drupal::request()->getHost();
      if (strpos($src, $base_path) === 0) {
        $location = 'local';
      } elseif (preg_match('/http[s]?:\/\/' . preg_quote($host . $base_path, '/') . '/', $src)) {
        $location = 'local';
      } elseif (strpos($src, 'http') === 0) {
        $location = 'remote';
      }

      // If not resizing images in this location or location is unknown, continue on to the next image.
      if ($location == 'unknown' || !$this->settings['image_locations'][$location]) {
        continue;
      }

      // Convert the URL to a local path.
      $local_path = NULL;
      if ($location == 'local') {
        // Remove the http:// and base path.
        $local_path = preg_replace('/(http[s]?:\/\/' . preg_quote($_SERVER['HTTP_HOST'], '/') . ')?' . preg_quote(base_path(), '/') . '/', '', $src, 1);

        // @todo In D8 do we need to do something to support multilaguage?
        $lang_codes = '';

        // Convert to a public file system URI.
        /** @var \Drupal\Core\StreamWrapper\StreamWrapperManager $stream_wrapper_manager * */
        $stream_wrapper_manager = \Drupal::service('stream_wrapper_manager');
        $public_wrapper = $stream_wrapper_manager->getViaScheme('public');
        $directory_path = $public_wrapper->getDirectoryPath() . '/';
        if (preg_match('!^' . preg_quote($directory_path, '!') . '!', $local_path)) {
          $local_path = 'public://' . preg_replace('!^' . preg_quote($directory_path, '!') . '!', '', $local_path);
        } // Convert to a file system path if using private files.
        elseif (preg_match('!^(\?q\=)?' . $lang_codes . 'system/files/!', $local_path)) {
          $local_path = 'private://' . preg_replace('!^(\?q\=)?' . $lang_codes . 'system/files/!', '', $local_path);
        }
        $local_path = rawurldecode($local_path);
      }

      // @todo Support get the original image from a preset
      if ($location == 'remote') {
        // Basic flood prevention on remote images.
        $resize_threshold = 10;
        // Basic flood prevention of resizing.
        $flood = \Drupal::flood();

        if (!$flood->isAllowed('macaroni_filter_image', $resize_threshold, 120)) {
          \Drupal::messenger()->addMessage(t('Image resize threshold of @count remote images has been reached. Please use fewer remote images.', array('@count' => $resize_threshold)), 'error', FALSE);
          continue;
        }
        $flood->register('macaroni_filter_image', 120);

        try {
          $result = \Drupal::httpClient()->get($src);
          if ($result->getStatusCode() == 200) {
            $tmp_file = \Drupal::service('file_system')->tempnam('temporary://', 'macaroni_filter_image_');
            $handle = fopen($tmp_file, 'w');
            fwrite($handle, $result->getBody());
            fclose($handle);
            $local_path = $tmp_file;
          }
        } catch (ClientException $e) {
          \Drupal::logger('macaroni_filter_image')->error('File %src was not found on remote server.', ['%src' => $src]);
        }
      }

      // Get the image size.
      if (is_file($local_path)) {
        $image_size = @getimagesize($local_path);
      }

      // All this work and the image isn't even there. Bummer. Next image please.
      if (empty($image_size)) {
        $this->deleteTemp($location, $local_path);
        continue;
      }

      $actual_width = (int)$image_size[0];
      $actual_height = (int)$image_size[1];

      // If either height or width is missing, calculate the other.
      if (empty($attributes['height']) && is_numeric($attributes['width'])) {
        $ratio = $actual_height / $actual_width;
        $attributes['height'] = (int)round($ratio * $attributes['width']);
      } elseif (empty($attributes['width']) && is_numeric($attributes['height'])) {
        $ratio = $actual_width / $actual_height;
        $attributes['width'] = (int)round($ratio * $attributes['height']);
      }

      // Skip processing if the image is a remote tracking image.
      if ($location == 'remote' && $actual_width == 1 && $actual_height == 1) {
        $this->deleteTemp($location, $local_path);
        continue;
      }

      // Check the image extension by name.
      $extension_matches = array();
      preg_match('/\.([a-zA-Z0-9]+)$/', $src, $extension_matches);
      if (!empty($extension_matches)) {
        $extension = strtolower($extension_matches[1]);
      }
      // If the name extension failed (such as an image generated by a script),
      // See if we can determine an extension by MIME type.
      elseif (isset($image_size['mime'])) {
        switch ($image_size['mime']) {
          case 'image/png':
            $extension = 'png';
            break;
          case 'image/gif':
            $extension = 'gif';
            break;
          case 'image/jpeg':
          case 'image/pjpeg':
            $extension = 'jpg';
            break;
        }
      }

      // If we're not certain we can resize this image, skip it.
      if (!isset($extension) || !in_array(strtolower($extension), array('png', 'jpg', 'jpeg', 'gif'))) {
        $this->deleteTemp($location, $local_path);
        continue;
      }

      if ($this->isAnimatedGif($local_path)) {
        $this->deleteTemp($location, $local_path);
        continue;
      }
      // If getting this far, the image exists and is not the right size, needs
      // to be saved locally from a remote server, or needs attributes added.
      // Add all information to a list of images that need resizing.
      $images[] = array(
        'actual_size' => array('width' => $image_size[0], 'height' => $image_size[1]),
        'attributes' => $attributes,
        'img_tag' => $img_tag,
        'original' => $src,
        'original_query' => $src_query,
        'location' => $location,
        'local_path' => $local_path,
        'mime' => $image_size['mime'],
        'extension' => $extension,
      );
    }

    return $images;
  }

  /**
   * Check if current image is animated
   * @param $filename
   * @return bool
   * return true if image is animated, false otherwise
   */
  private function isAnimatedGif($filename)
  {
    $raw = file_get_contents($filename);

    $offset = 0;
    $frames = 0;
    while ($frames < 2) {
      $where1 = strpos($raw, "\x00\x21\xF9\x04", $offset);
      if ($where1 === false) {
        break;
      } else {
        $offset = $where1 + 1;
        $where2 = strpos($raw, "\x00\x2C", $offset);
        if ($where2 === false) {
          break;
        } else {
          if ($where1 + 8 == $where2) {
            $frames++;
          }
          $offset = $where2 + 1;
        }
      }
    }

    return $frames > 1;
  }

  /**
   * A short-cut function to delete temporary remote images.
   */
  private function deleteTemp($source, $uri)
  {
    if ($source == 'remote' && is_file($uri)) {
      @unlink($uri);
    }
  }

  /**
   * Generate a themed image tag based on an image array.
   *
   * @param $image
   *   An array containing image information and properties.
   * @param $settings
   *   Settings for the input filter.
   */
  private function getImageTag($image = NULL, $settings = NULL)
  {
    $src = file_create_url($image['destination']);

    // Strip the http:// from the path if the original did not include it.
    if (!preg_match('/^http[s]?:\/\/' . preg_quote($_SERVER['HTTP_HOST']) . '/', $image['original'])) {
      $src = preg_replace('/^http[s]?:\/\/' . preg_quote($_SERVER['HTTP_HOST']) . '/', '', $src);
    }

    // Restore any URL query.
    if (isset($image['original_query'])) {
      $src .= '?' . $image['original_query'];
      $image['original'] .= '?' . $image['original_query'];
    }

    $image['attributes']['src'] = $src;

    return '<img' . new Attribute($image['attributes']) . ' />';
  }

  /**
   * Utility function to return path information.
   */
  private function getPathInfo($uri)
  {
    $info = pathinfo($uri);
    $info['extension'] = substr($uri, strrpos($uri, '.') + 1);
    $info['basename'] = basename($uri);
    $info['filename'] = basename($uri, '.' . $info['extension']);
    // Once Drupal 8.7.x is unsupported remove this IF statement.
    if (floatval(\Drupal::VERSION) >= 8.8) {
      $info['scheme'] = \Drupal::service('stream_wrapper_manager')->getScheme($uri);
    } else {
      $info['scheme'] = \Drupal::service('file_system')->uriScheme($uri);
    }

    $stream_wrapper_manager = \Drupal::service('stream_wrapper_manager');
    $stream_wrappers = $stream_wrapper_manager->getWrappers();
    if (empty($info['scheme'])) {
      foreach ($stream_wrappers as $scheme => $stream_wrapper) {
        $scheme_base_path = $stream_wrapper_manager->getViaScheme($scheme)->getDirectoryPath();
        $matches = array();
        if (preg_match('/^' . preg_quote($scheme_base_path, '/') . '\/?(.*)/', $info['dirname'], $matches)) {
          $info['scheme'] = $scheme;
          $info['dirname'] = $scheme . '://' . $matches[1];
          break;
        }
      }
    }

    return $info;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state)
  {

    $settings['image_locations'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Scale images stored'),
      '#options' => [
        'local' => $this->t('Locally'),
        'remote' => $this->t('On remote servers (note: this copies <em>all</em> remote images locally)')
      ],
      '#default_value' => array_keys(array_filter($this->settings['image_locations'])),
      '#description' => $this->t('This option will determine which images will be analyzed for &lt;img&gt; tag differences. Enabling scaling of remote images can have performance impacts, as all images in the filtered text needs to be transferred via HTTP each time the filter cache is cleared.'),
    ];

    $settings['image_scale'] = [
      '#type' => 'number',
      '#title' => $this->t('Desired width (in pixels) to scale images'),
      '#default_value' => $this->settings['image_scale'],
      '#description' => $this->t('This option will determine the width (in pixels) to the images will be scaled'),
    ];

    return $settings;
  }
}
