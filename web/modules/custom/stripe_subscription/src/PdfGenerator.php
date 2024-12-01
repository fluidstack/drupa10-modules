<?php

namespace Drupal\stripe_subscription;

use Dompdf\Dompdf;
use Dompdf\Options;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Render\RendererInterface;

/**
 * Service for generating PDFs.
 */
class PdfGenerator {

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructs a new PdfGenerator.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   */
  public function __construct(
    RendererInterface $renderer,
    FileSystemInterface $file_system
  ) {
    $this->renderer = $renderer;
    $this->fileSystem = $file_system;
  }

  /**
   * Generates a PDF from HTML content.
   *
   * @param string $html
   *   The HTML content.
   * @param array $options
   *   An array of PDF options.
   *
   * @return string
   *   The generated PDF content.
   */
  public function generatePdf($html, array $options = []) {
    // Initialize PDF options
    $pdf_options = new Options();
    $pdf_options->set('isHtml5ParserEnabled', true);
    $pdf_options->set('isPhpEnabled', true);
    $pdf_options->set('defaultFont', 'Arial');
    $pdf_options->set('chroot', $this->fileSystem->realpath('public://'));

    // Create Dompdf instance
    $dompdf = new Dompdf($pdf_options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper($options['paper'] ?? 'A4', $options['orientation'] ?? 'portrait');
    $dompdf->render();

    return $dompdf->output();
  }

  /**
   * Generates a PDF from a template.
   *
   * @param string $theme
   *   The theme name.
   * @param array $variables
   *   The template variables.
   * @param array $options
   *   An array of PDF options.
   *
   * @return string
   *   The generated PDF content.
   */
  public function generatePdfFromTemplate($theme, array $variables, array $options = []) {
    $build = [
      '#theme' => $theme,
      '#receipt' => $variables,
    ];

    $html = $this->renderer->render($build);
    return $this->generatePdf($html, $options);
  }

}