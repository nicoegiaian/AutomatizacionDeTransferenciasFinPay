<?php

namespace App\Service\Report;

use Dompdf\Dompdf;
use Dompdf\Options;
use setasign\Fpdi\Fpdi;
use Twig\Environment;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Psr\Log\LoggerInterface;

class PdfReportGenerator
{
    private Environment $twig;
    private Filesystem $filesystem;
    private LoggerInterface $logger;
    private string $projectDir;
    private string $outputDir;

    private const MESES_CARPETA = [
        1=>'01_Ene', 2=>'02_Feb', 3=>'03_Mar', 4=>'04_Abr', 5=>'05_May', 6=>'06_Jun',
        7=>'07_Jul', 8=>'08_Ago', 9=>'09_Sep', 10=>'10_Oct', 11=>'11_Nov', 12=>'12_Dic'
    ];
    private const MESES_CORTO = [
        1=>'Ene', 2=>'Feb', 3=>'Mar', 4=>'Abr', 5=>'May', 6=>'Jun',
        7=>'Jul', 8=>'Ago', 9=>'Sep', 10=>'Oct', 11=>'Nov', 12=>'Dic'
    ];

    public function __construct(
        Environment $twig,
        #[Autowire('%kernel.project_dir%')] string $projectDir,
        #[Autowire('%env(resolve:PATH_RESUMENES_PDF)%')] string $outputDir,
        LoggerInterface $logger
    ) {
        $this->twig = $twig;
        $this->filesystem = new Filesystem();
        $this->projectDir = $projectDir;
        $this->outputDir = $outputDir;
        $this->logger = $logger;
    }

    // --- RECURSOS ---
    private function getResources(): array {
        $imgDir = $this->projectDir . '/public';
        return [
            'images' => [
                'encabezado' => $this->getBase64($imgDir . '/img/encabezado.png'),
                'pie' => $this->getBase64($imgDir . '/img/pie.png')
            ],
            'font' => $this->getBase64($imgDir . '/public/fonts/AmasisMTPro-Light.ttf')
        ];
    }

    private function getBase64(string $path): string {
        return file_exists($path) ? base64_encode(file_get_contents($path)) : '';
    }

    private function renderPdf(string $html): string {
        $dompdf = new Dompdf();
        $options = $dompdf->getOptions();
        $options->setIsRemoteEnabled(true); 
        $dompdf->setOptions($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return $dompdf->output();
    }

    private function getTargetDir(int $month, int $year): string {
        $folderName = self::MESES_CARPETA[$month];
        $dir = sprintf('%s/%d/%s', $this->outputDir, $year, $folderName);
        if (!$this->filesystem->exists($dir)) {
            $this->filesystem->mkdir($dir, 0755);
        }
        return $dir;
    }

    private function getFormattedDateString(int $month, int $year): string {
        $mes = self::MESES_CORTO[$month];
        $anio = substr((string)$year, 2);
        return sprintf("%s %s", $mes, $anio);
    }

    // --- MAESTRO GLOBAL (Carátula Global + Separadores + Unidades) ---
    public function generateGlobalMaster(array $globalData, array $filesToMerge, int $month, int $year): string
    {
        $res = $this->getResources();
        $targetDir = $this->getTargetDir($month, $year);
        $dateStr = $this->getFormattedDateString($month, $year);

        $filename = sprintf("MOURA %s.pdf", $dateStr);
        $outputPath = $targetDir . '/' . $filename;

        $pdf = new Fpdi();

        // 1. Carátula Global (Hoja 1)
        $htmlGlobal = $this->twig->render('reports/moura_summary.html.twig', [
            'report' => $globalData,
            'type' => 'summary', 
            'images' => $res['images'],
            'font_amasis' => $res['font']
        ]);
        $this->appendHtmlToPdf($pdf, $htmlGlobal);

        // --- PREPARAR SEPARADOR ---
        // Generamos el HTML del separador una sola vez para reutilizarlo
        $htmlSeparator = $this->twig->render('reports/moura_summary.html.twig', [
            'title_separator' => 'Detalle por Unidad de Negocio',
            'type' => 'separator',
            'images' => $res['images'], // Respeta encabezado y pie
            'font_amasis' => $res['font']
        ]);

        // 2. Anexar Unidades con sus Separadores
        foreach ($filesToMerge as $file) {
            if (file_exists($file)) {
                // A. Insertar Carátula "Detalle por Unidad de Negocio"
                $this->appendHtmlToPdf($pdf, $htmlSeparator);

                // B. Pegar el PDF completo de la Unidad (que ya trae su resumen y sus PDVs)
                $this->appendExistingPdfToPdf($pdf, $file);
            } else {
                $this->logger->warning("Archivo de Unidad para fusionar no encontrado: $file");
            }
        }

        $pdf->Output('F', $outputPath);
        return $outputPath;
    }

    // --- MAESTRO POR UNIDAD (Carátula + Separador + Hijos) ---
    public function generateUnitMaster(string $unitName, array $unitData, array $pdvFiles, int $month, int $year): string
    {
        $res = $this->getResources();
        $targetDir = $this->getTargetDir($month, $year);
        $dateStr = $this->getFormattedDateString($month, $year);

        $filename = sprintf("MOURA %s %s.pdf", $unitName, $dateStr);
        $outputPath = $targetDir . '/' . $filename;

        $pdf = new Fpdi();

        // 1. Carátula Unidad
        $htmlUnit = $this->twig->render('reports/moura_summary.html.twig', [
            'report' => $unitData,
            'type' => 'summary',
            'images' => $res['images'],
            'font_amasis' => $res['font']
        ]);
        $this->appendHtmlToPdf($pdf, $htmlUnit);

        // 2. Separador
        $htmlSep = $this->twig->render('reports/moura_summary.html.twig', [
            'title_separator' => 'ANEXO: Detalle por punto de venta',
            'type' => 'separator',
            'images' => $res['images'],
            'font_amasis' => $res['font']
        ]);
        $this->appendHtmlToPdf($pdf, $htmlSep);

        // 3. Adjuntar hijos
        foreach ($pdvFiles as $file) {
            if (file_exists($file)) {
                $this->appendExistingPdfToPdf($pdf, $file);
            } else {
                $this->logger->warning("Archivo hijo no encontrado: $file");
            }
        }

        $pdf->Output('F', $outputPath);
        return $outputPath;
    }

    // --- HELPERS (FPDI con Logs) ---
    private function appendHtmlToPdf(Fpdi $pdf, string $html): void {
        $content = $this->renderPdf($html);
        $tmpFile = tempnam(sys_get_temp_dir(), 'dompdf_frag');
        file_put_contents($tmpFile, $content);
        try {
            $count = $pdf->setSourceFile($tmpFile);
            for ($i = 1; $i <= $count; $i++) {
                $id = $pdf->importPage($i);
                $pdf->AddPage();
                $pdf->useTemplate($id);
            }
        } catch (\Exception $e) {
            $this->logger->error("Error adjuntando HTML: " . $e->getMessage());
        }
        @unlink($tmpFile);
    }

    private function appendExistingPdfToPdf(Fpdi $pdf, string $path): void {
        try {
            $count = $pdf->setSourceFile($path);
            for ($i = 1; $i <= $count; $i++) {
                $id = $pdf->importPage($i);
                $pdf->AddPage();
                $pdf->useTemplate($id);
            }
        } catch (\Exception $e) {
            $this->logger->error("Error adjuntando PDF ($path): " . $e->getMessage());
        }
    }

    public function generatePdvReport(array $data, int $month, int $year): string {
        $res = $this->getResources();
        $html = $this->twig->render('reports/pdv_settlement.html.twig', [
            'report' => $data, 'images' => $res['images'], 'font_amasis' => $res['font']
        ]);
        $pdfContent = $this->renderPdf($html);
        
        $mes = self::MESES_CORTO[$month];
        $anio = substr((string)$year, 2);
        $razon = str_replace(['/', '\\'], '-', $data['header']['razon_social']);
        
        $filename = sprintf('%s %s %s.pdf', $razon, $mes, $anio);
        $fullPath = $this->getTargetDir($month, $year) . '/' . $filename;
        
        file_put_contents($fullPath, $pdfContent);
        return $fullPath;
    }
}