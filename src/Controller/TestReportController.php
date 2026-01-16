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
        // 1. Datos Mock
        $mockReport = [
            // ... (tus datos del reporte igual que antes) ...
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

        // 2. Carga inteligente de imágenes (Corrección de claves y formato)
        // Buscamos la carpeta public real de tu proyecto
        $publicDir = $this->getParameter('kernel.project_dir') . '/public';
        
        // Función auxiliar para convertir a Base64 si el archivo existe
        $imageLoader = function($path) use ($publicDir) {
            $fullPath = $publicDir . $path;
            if (file_exists($fullPath)) {
                return base64_encode(file_get_contents($fullPath));
            }
            return ''; // Retorna vacío si no encuentra la imagen para que no falle
        };

        $mockImages = [
            // AHORA SÍ: Las claves en español que espera tu Twig
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
            // Datos Mock similares al ejemplo anterior, adaptados al layout de Moura
            $mockReport = [
                'header' => [
                    'titulo' => 'Resumen total Liquidaciones Cobres Flex',
                    'unidad_de_negocio' => 'Buenos Aires',
                    'periodo' => 'Noviembre 2025'                
                ],
                'items' => [
                    'debito' => ['cant' => 5, 'monto' => 820970.00],
                    'credito' => ['cant' => 37, 'monto' => 5832165.73],
                    'qr' => ['cant' => 5, 'monto' => 1200.55],
                ],
                'totals' => [
                    'transacciones' => 42,
                    'bruto' => 6653135.73,
                    'costo_de_servicio' => 0,
                    'costo_de_financiacion' => 66135.73,
                    'aranceles_tarjetas' => 455.55,
                    'IVA' => 3732.54,
                    'otros_impuestos' => 111546.72,
                    'neto_percibido' => 42970.09,
                    'en_cuenta_comercio' => 49197.10,
                    'en_cc_moura' => 6210043.23,
                    'costo_servicio_bloque' => 1863012.97,
                    'transferencia_moura' => 4347030.32,
                    'beneficio_credmoura' => 137053.99 
                ]
            ];
    
            // Carga de imágenes adaptada al layout de Moura
            $publicDir = $this->getParameter('kernel.project_dir') . '/public';
            
            $imageLoader = function($path) use ($publicDir) {
                $fullPath = $publicDir . $path;
                if (file_exists($fullPath)) {
                    return base64_encode(file_get_contents($fullPath));
                }
                return '';
            };
    
            $mockImages = [
                'encabezado' => $imageLoader('/img/encabezado.png'), 
                'pie'        => $imageLoader('/img/pie.png')
            ];
    
            return $this->render('reports/moura_summary.html.twig', [
                'report' => $mockReport,
                'images' => $mockImages,
                'is_preview' => true 
            ]);
        }
}