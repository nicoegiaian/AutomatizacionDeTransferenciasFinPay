<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TestReportController extends AbstractController
{
    #[Route('/test-pdv-layout', name: 'test_pdv_layout')]
    public function preview(): Response
    {
        // 1. Datos Mock (Legacy para PDV individual)
        $mockReport = [
            'header' => [
                'razon_social' => 'INTERBAT SRL',
                'cuit' => '30-12345678-9',
                'periodo' => 'Noviembre 2025',
                'nro_resumen' => '123456789'
            ],
            'items' => [
                'debito' => ['cant' => 5, 'monto' => 820970.00],
                'credito' => ['cant' => 37, 'monto' => 5832165.73],
                'qr' => ['cant' => 5, 'monto' => 1200.55],
                'prepago' => ['cant' => 12, 'monto' => 6500.55],
            ],
           'totales' => [
                'transacciones' => 42,
                'bruto' => 6653135.73,
                'costo_servicio' => 0,
                'costo_financiacion' => 376432.54,
                'aranceles' => 111546.72,
                'iva' => 42970.09,
                'otros_impuestos' => 49197.10,
                'neto_percibido' => 6210043.23,
                'en_cuenta_comercio' => 1863012.97,
                'en_cc_moura' => 4347030.32,
                'beneficio_credmoura' => 137053.99 
            ]
        ];

        $publicDir = $this->getParameter('kernel.project_dir') . '/public';
        
        $imageLoader = function($path) use ($publicDir) {
            $fullPath = $publicDir . $path;
            return file_exists($fullPath) ? base64_encode(file_get_contents($fullPath)) : '';
        };

        $mockImages = [
            'encabezado' => $imageLoader('/img/encabezado.png'), 
            'pie'        => $imageLoader('/img/pie.png')
        ];

        return $this->render('reports/pdv_settlement.html.twig', [
            'report' => $mockReport,
            'images' => $mockImages,
            'is_preview' => true 
        ]);
    }

   #[Route('/test-moura-layout', name: 'test_moura_layout')]
    public function preview_moura(): Response
    {
        // ... (Carga de imágenes y fuentes igual que antes) ...
        // ...

        $mockReport = [
            'header' => [
                'titulo' => 'Resumen total Liquidaciones Cobres Flex',
                'unidad_de_negocio' => 'Buenos Aires (Test)',
                'periodo' => 'Noviembre 2025'                
            ],
            'items' => [
                'debito' => ['cant' => 5, 'monto' => 820970.00],
                'credito' => ['cant' => 37, 'monto' => 5832165.73],
                'qr' => ['cant' => 5, 'monto' => 1200.55],
                'prepago' => ['cant' => 12, 'monto' => 6500.55],
            ],
            'totales' => [
                'transacciones' => 47,
                'bruto' => 6654336.28,
                
                // --- AQUÍ ESTÁN LOS CAMPOS CALCULADOS ---
                'costo_servicio' => 199630.09,       // 3%
                'iva' => 41922.32,                   // 21% del 3%
                'beneficio_credmoura' => 33271.68,   // 0.5%
                
                'neto_percibido' => 6210043.23,
                'en_cuenta_comercio' => 1863012.97,
                'en_cc_moura' => 4347030.32,
                'transferencia_moura' => 4200000.00, 
            ]
        ];

        return $this->render('reports/moura_summary.html.twig', [
            'report' => $mockReport,
            'images' => $mockImages,
            'font_amasis' => $fontBase64,
            'type' => 'summary',
            'is_preview' => true
        ]);
    }
}