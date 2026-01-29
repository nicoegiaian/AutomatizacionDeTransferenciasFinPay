<?php

namespace App\Command;

use App\Service\Report\MonthlySettlementService;
use App\Service\Report\PdfReportGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:generate-monthly-reports',
    description: 'Genera reportes PDF mensuales y el anexo consolidado de Moura.',
)]
class GenerateMonthlyReportsCommand extends Command
{
    private MonthlySettlementService $settlementService;
    private PdfReportGenerator $pdfGenerator;

    public function __construct(MonthlySettlementService $settlementService, PdfReportGenerator $pdfGenerator)
    {
        $this->settlementService = $settlementService;
        $this->pdfGenerator = $pdfGenerator;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('month', null, InputOption::VALUE_OPTIONAL, 'Mes (1-12)')
            ->addOption('year', null, InputOption::VALUE_OPTIONAL, 'Año (ej: 2025)')
            ->addOption('test-one', null, InputOption::VALUE_NONE, 'Si se activa, solo genera 1 reporte para probar');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // 1. Resolver Fecha (Default: Mes anterior)
        $now = new \DateTime();
        $month = $input->getOption('month') ? (int)$input->getOption('month') : (int)$now->modify('-1 month')->format('n');
        
        if ($input->getOption('year')) {
            $year = (int)$input->getOption('year');
        } else {
            $year = (int)$now->format('Y'); 
        }

        $output->writeln("=== Iniciando Generación: $month / $year ===");

        try {
            // ---------------------------------------------------------
            // PASO CLAVE: TRAEMOS TODOS LOS DATOS DE LA BD AL PRINCIPIO
            // ---------------------------------------------------------
            
            // A. Datos Individuales
            $output->writeln("Consultando datos individuales...");
            $data = $this->settlementService->getMonthlyData($month, $year);
            $count = count($data);
            $output->writeln("Comercios con movimientos: $count");

            if ($count === 0) {
                $output->writeln("No hay datos para procesar.");
                return Command::SUCCESS;
            }

            // B. Datos Agrupados (Moura) - LO HACEMOS AHORA PARA EVITAR EL TIMEOUT DE MYSQL
            // Si lo dejamos para el final, la conexión puede morir mientras generamos los PDFs.
            $output->writeln("Consultando resúmenes de Moura...");
            $mouraSummaries = $this->settlementService->getMouraSummaries($month, $year);

            // ---------------------------------------------------------
            // FIN DE INTERACCIÓN CON BD - AHORA SOLO PROCESAMIENTO PHP
            // ---------------------------------------------------------

            // 3. Generar Individuales y Agrupar por Unidad
            $generatedFiles = [];       
            $pdvFilesMap = [];          
            
            $limit = $input->getOption('test-one') ? 1 : 999999;
            $i = 0;

            foreach ($data as $pdvData) {
                if ($i >= $limit) {
                    $output->writeln("<comment>Modo Test: Deteniendo tras generar 1 reporte.</comment>");
                    break;
                }

                $razon = $pdvData['header']['razon_social'];
                
                // Generamos el PDF individual (Esto tarda, pero ya no importa si se cae la BD)
                $path = $this->pdfGenerator->generatePdvReport($pdvData, $month, $year);
                
                $generatedFiles[] = $path;

                // Agrupación
                $unidad = $pdvData['header']['unidad_de_negocio'] ?? 'Sin Unidad';
                
                if (!isset($pdvFilesMap[$unidad])) {
                    $pdvFilesMap[$unidad] = [];
                }
                $pdvFilesMap[$unidad][] = $path;
                
                $output->writeln(" -> OK [$unidad]: $razon");
                $i++;
            }

            // 4. Generar Reporte Full Moura (Usando los datos que trajimos al principio)
            if ($i > 0) {
                $output->writeln("Generando Reporte Consolidado Moura...");
                
                // Ya tenemos $mouraSummaries en memoria, no hacemos query aquí.
                $finalPath = $this->pdfGenerator->generateMouraFullReport(
                    $mouraSummaries, 
                    $pdvFilesMap, 
                    $month, 
                    $year
                );
                
                $output->writeln("<info> -> REPORTE MOURA COMPLETO: $finalPath</info>");
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln("<error>Error Crítico: " . $e->getMessage() . "</error>");
            $output->writeln($e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}