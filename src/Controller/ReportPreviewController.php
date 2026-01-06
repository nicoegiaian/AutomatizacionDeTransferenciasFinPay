<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class ReportPreviewController extends AbstractController
{
    #[Route('/test/preview-report', name: 'test_preview_report')]
    public function preview(#[Autowire('%kernel.project_dir%')] string $projectDir): Response
    {
        // Datos Dummy para probar diseño
        $dummyData = [
            'header' => [
                'razon_social' => 'NOURIAN CRISTIAN (VISTA PREVIA)',
                'cuit' => '20-31662695-6',
                'periodo' => 'Noviembre 2025',
                'nro_resumen' => '11672112025',
            ],
            'items' => [
                'debito' => ['cant' => 31, 'monto' => 8178161.01],
                'credito' => ['cant' => 47, 'monto' => 4524843.45],
                'qr' => ['cant' => 10, 'monto' => 150000.00],
                'devoluciones' => ['cant' => 0, 'monto' => 0.0],
            ],
            'totales' => [
                'bruto' => 12703004.46,
                'transacciones' => 78,
                'costo_servicio' => 0.00,
                'costo_financiacion' => 654712.70,
                'aranceles' => 187215.52,
                'iva' => 78341.56,
                'otros_impuestos' => 76218.04,
                'neto_percibido' => 12053063.23,
                'en_cuenta_comercio' => 0.00,
                'en_cc_moura' => 12053063.23,
                'beneficio_credmoura' => 346546.62,
            ]
        ];

        return $this->render('reports/pdv_settlement.html.twig', [
            'report' => $dummyData,
            'images_dir' => $projectDir . '/public/img'
        ]);
    }
}