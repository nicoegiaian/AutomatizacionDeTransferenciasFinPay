<?php

namespace App\Service\Report;

use Dompdf\Dompdf;
use Dompdf\Options;
use setasign\Fpdi\Fpdi;
use Twig\Environment;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class PdfReportGenerator
{
    private Environment $twig;
    private Filesystem $filesystem;
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
        #[Autowire('%env(resolve:PATH_RESUMENES_PDF)%')] string $outputDir
    ) {
        $this->twig = $twig;
        $this->filesystem = new Filesystem();
        $this->projectDir = $projectDir;
        $this->outputDir = $outputDir;
    }

    private function getBase64Image(string $relativePath): string
    {
        $path = $this->projectDir . '/public/' . $relativePath;
        if (!file_exists($path)) return '';
        return base64_encode(file_get_contents($path));
    }
    
    private function getBase64Font(): string {
        $path = $this->projectDir . '/public/fonts/AmasisMTPro-Light.ttf';
        if (!file_exists($path)) return '';
        return base64_encode(file_get_contents($path));
    }

    private function renderPdf(string $html): string
    {
        $dompdf = new Dompdf();
        $options = $dompdf->getOptions();
        $options->setIsRemoteEnabled(true); 
        $dompdf->setOptions($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return $dompdf->output();
    }

    private function getTargetDir(int $month, int $year): string
    {
        $folderName = self::MESES_CARPETA[$month];
        $dir = sprintf('%s/%d/%s', $this->outputDir, $year, $folderName);
        if (!$this->filesystem->exists($dir)) {
            $this->filesystem->mkdir($dir, 0755);
        }
        return $dir;
    }

    // --- CORRECCIÓN AQUÍ: Renombrado a generateMouraFullReport ---
    public function generateMouraFullReport(array $summaryData, array $pdvFilesMap, int $month, int $year): string
    {
        // 1. Preparar recursos comunes (imágenes y fuentes)
        $images = [
            'encabezado' => $this->getBase64Image('img/encabezado.png'),
            'pie' => $this->getBase64Image('img/pie.png')
        ];
        $fontAmasis = $this->getBase64Font();
        $targetDir = $this->getTargetDir($month, $year);

        // Inicializamos FPDI para unir todo
        $pdf = new Fpdi();

        // --- PASO 1: Carátula Global (Hoja 1) ---
        $htmlGlobal = $this->twig->render('reports/moura_summary.html.twig', [
            'report' => $summaryData['global'],
            'type' => 'summary', 
            'images' => $images,
            'font_amasis' => $fontAmasis
        ]);
        $this->appendHtmlToPdf($pdf, $htmlGlobal);

        // --- PASO 2: Separador "Apertura" (Hoja 2) ---
        $htmlSep1 = $this->twig->render('reports/moura_summary.html.twig', [
            'title_separator' => 'Apertura por Unidad de Negocio',
            'type' => 'separator', 
            'images' => $images,
            'font_amasis' => $fontAmasis
        ]);
        $this->appendHtmlToPdf($pdf, $htmlSep1);

        // --- PASO 3: Iterar Unidades ---
        foreach ($summaryData['units'] as $unidadNombre => $unitData) {
            
            // a) Carátula de Unidad
            $htmlUnit = $this->twig->render('reports/moura_summary.html.twig', [
                'report' => $unitData,
                'type' => 'summary',
                'images' => $images,
                'font_amasis' => $fontAmasis
            ]);
            $this->appendHtmlToPdf($pdf, $htmlUnit);

            // b) Separador "ANEXO"
            $htmlSepAnexo = $this->twig->render('reports/moura_summary.html.twig', [
                'title_separator' => 'ANEXO: Detalle por punto de venta',
                'type' => 'separator',
                'images' => $images,
                'font_amasis' => $fontAmasis
            ]);
            $this->appendHtmlToPdf($pdf, $htmlSepAnexo);

            // c) Adjuntar PDFs de los Puntos de Venta
            if (isset($pdvFilesMap[$unidadNombre])) {
                foreach ($pdvFilesMap[$unidadNombre] as $pdvFile) {
                    if (file_exists($pdvFile)) {
                        $this->appendExistingPdfToPdf($pdf, $pdvFile);
                    }
                }
            }
        }

        // Guardar archivo final
        $outputPath = $targetDir . '/MOURA_REPORTE_MENSUAL.pdf';
        $pdf->Output('F', $outputPath);

        return $outputPath;
    }

    private function appendHtmlToPdf(Fpdi $pdf, string $html): void {
        $content = $this->renderPdf($html);
        $tmpFile = tempnam(sys_get_temp_dir(), 'dompdf_frag');
        file_put_contents($tmpFile, $content);

        try {
            $pageCount = $pdf->setSourceFile($tmpFile);
            for ($i = 1; $i <= $pageCount; $i++) {
                $tplId = $pdf->importPage($i);
                $pdf->AddPage();
                $pdf->useTemplate($tplId);
            }
        } catch (\Exception $e) { }
        @unlink($tmpFile);
    }

    private function appendExistingPdfToPdf(Fpdi $pdf, string $path): void {
        try {
            $pageCount = $pdf->setSourceFile($path);
            for ($i = 1; $i <= $pageCount; $i++) {
                $tplId = $pdf->importPage($i);
                $pdf->AddPage();
                $pdf->useTemplate($tplId);
            }
        } catch (\Exception $e) { }
    }

     public function generatePdvReport(array $data, int $month, int $year): string
    {
        $images = [
            'encabezado' => $this->getBase64Image('img/encabezado.png'),
            'pie' => $this->getBase64Image('img/pie.png')
        ];

        $fontPath = $this->projectDir . '/public/fonts/Amasis MT Std Light.ttf';
        $fontBase64 = '';
        
        if (file_exists($fontPath)) {
            $fontBase64 = base64_encode(file_get_contents($fontPath));
        }

        $html = $this->twig->render('reports/pdv_settlement.html.twig', [
            'report' => $data,
            'images' => $images,
            'font_amasis' => $fontBase64 
        ]);

        $pdfContent = $this->renderPdf($html);
        
        $mesCorto = self::MESES_CORTO[$month];
        $anioCorto = substr((string)$year, 2);
        $razonLimpia = str_replace(['/', '\\'], '-', $data['header']['razon_social']);
        
        $filename = sprintf('%s %s %s.pdf', $razonLimpia, $mesCorto, $anioCorto);
        $fullPath = $this->getTargetDir($month, $year) . '/' . $filename;

        file_put_contents($fullPath, $pdfContent);
        return $fullPath;
    }
}