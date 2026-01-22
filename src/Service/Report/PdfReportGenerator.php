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

    // --- Métodos Privados de Utilidad ---

    private function getBase64Image(string $relativePath): string
    {
        $path = $this->projectDir . '/public/' . $relativePath;
        if (!file_exists($path)) {
            return ''; // O retornar una imagen transparente por defecto
        }
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
        // Construye ruta: /var/www/html/portal/resumenes/2025/11_Nov
        $folderName = self::MESES_CARPETA[$month];
        $dir = sprintf('%s/%d/%s', $this->outputDir, $year, $folderName);
        if (!$this->filesystem->exists($dir)) {
            $this->filesystem->mkdir($dir, 0755);
        }
        return $dir;
    }

    // --- Métodos Públicos ---

    public function generatePdvReport(array $data, int $month, int $year): string
    {
        // 1. Preparar imágenes para inyectar (evita líos de rutas en CLI)
        $images = [
            'encabezado' => $this->getBase64Image('img/encabezado.png'),
            'pie' => $this->getBase64Image('img/pie.png')
        ];

        $fontPath = $this->projectDir . '/public/fonts/Amasis MT Std Light.ttf';
        $fontBase64 = '';
        
        if (file_exists($fontPath)) {
            $fontBase64 = base64_encode(file_get_contents($fontPath));
        }

        // 2. Renderizar HTML
        $html = $this->twig->render('reports/pdv_settlement.html.twig', [
            'report' => $data,
            'images' => $images
            'font_amasis' => $fontBase64 
        ]);

        // 3. Generar y Guardar
        $pdfContent = $this->renderPdf($html);
        
        $mesCorto = self::MESES_CORTO[$month];
        $anioCorto = substr((string)$year, 2);
        $razonLimpia = str_replace(['/', '\\'], '-', $data['header']['razon_social']);
        
        $filename = sprintf('%s %s %s.pdf', $razonLimpia, $mesCorto, $anioCorto);
        $fullPath = $this->getTargetDir($month, $year) . '/' . $filename;

        file_put_contents($fullPath, $pdfContent);
        return $fullPath;
    }

    public function generateMouraCover(array $totals, int $month, int $year): string
    {
        // 1. Cargar Imágenes (lo que ya tenías)
        $images = [
            'encabezado' => $this->getBase64Image('img/encabezado.png'),
            'pie' => $this->getBase64Image('img/pie.png')
        ];

        // 2. NUEVO: Cargar Fuente Amasis
        // Asumiendo que el archivo se llama "Amasis MT Std Light.ttf"
        $fontPath = $this->projectDir . '/public/fonts/Amasis MT Std Light.ttf';
        $fontBase64 = '';
        
        if (file_exists($fontPath)) {
            $fontBase64 = base64_encode(file_get_contents($fontPath));
        }

        // 3. Renderizar (Pasamos la variable 'font_amasis')
        $html = $this->twig->render('reports/moura_summary.html.twig', [
            'report' => $totals,
            'images' => $images,
            'font_amasis' => $fontBase64 // <--- Nueva Variable
        ]);

        $pdfContent = $this->renderPdf($html);
        
        // ... (resto del código igual)
        $tempPath = $this->getTargetDir($month, $year) . '/_temp_moura_cover.pdf';
        file_put_contents($tempPath, $pdfContent);
        
        return $tempPath;
    }

    public function generateMouraAnnex(string $coverPath, array $pdvFiles, int $month, int $year): string
    {
        $pdf = new Fpdi();
        
        // --- PASO 1: LA CARÁTULA (Resumen Moura) ---
        if (file_exists($coverPath)) {
            try {
                $pageCount = $pdf->setSourceFile($coverPath);
                for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                    $templateId = $pdf->importPage($pageNo);
                    $pdf->AddPage(); // Agrega la página
                    $pdf->useTemplate($templateId);
                }
            } catch (\Exception $e) {
                // Manejo de error si falla la carátula
            }
        }

        // --- PASO 2: EL SEPARADOR (Nueva hoja generada al vuelo) ---
        $pdf->AddPage(); // Crea una hoja blanca nueva
        
        // Configuramos fuente Helvética, Negrita, tamaño 20
        $pdf->SetFont('Helvetica', 'B', 20);
        
        // Escribimos el texto centrado
        // Los parámetros son: (width, height, text, border, ln, align)
        // Usamos Cell(0, ...) para que ocupe todo el ancho y 'C' para centrar
        
        // Bajamos un poco el cursor para que quede verticalmente mejor (aprox mitad de hoja A4)
        $pdf->SetY(130); 
        $pdf->Cell(0, 10, 'Detalles por punto de Venta', 0, 1, 'C');


        // --- PASO 3: LOS ANEXOS (PDFs individuales) ---
        foreach ($pdvFiles as $file) {
            if (!file_exists($file)) continue;
            try {
                $pageCount = $pdf->setSourceFile($file);
                for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                    $templateId = $pdf->importPage($pageNo);
                    $pdf->AddPage();
                    $pdf->useTemplate($templateId);
                }
            } catch (\Exception $e) { continue; }
        }

        // Eliminar carátula temporal
        if ($this->filesystem->exists($coverPath)) {
            $this->filesystem->remove($coverPath);
        }

        // --- PASO 4: NOMBRE DEL ARCHIVO (Moura mmm aa) ---
        // Ejemplo: Moura Nov 25
        $mesTexto = self::MESES_CORTO[$month]; // "Nov"
        $anioCorto = substr((string)$year, -2); // "25"
        
        $filename = sprintf('MOURA %s %s.pdf', $mesTexto, $anioCorto);
        $outputPath = $this->getTargetDir($month, $year) . '/' . $filename;

        $pdf->Output('F', $outputPath);

        return $outputPath;
    }
}