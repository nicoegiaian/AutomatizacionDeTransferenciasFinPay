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
    description: 'Genera reportes PDF mensuales en la carpeta externa configurada.',
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
            // Si modificamos $now arriba, el año ya podría haber cambiado (ej: Enero -> Diciembre año anterior)
            $year = (int)$now->format('Y'); 
        }

        $output->writeln("=== Iniciando Generación: $month / $year ===");

        try {
            // 2. Obtener Datos
            $data = $this->settlementService->getMonthlyData($month, $year);
            $count = count($data);
            $output->writeln("Comercios con movimientos: $count");

            if ($count === 0) return Command::SUCCESS;

            // 3. Generar Individuales
            $generatedFiles = [];
            $limit = $input->getOption('test-one') ? 1 : 999999;
            $i = 0;

            foreach ($data as $pdvData) {
                if ($i >= $limit) {
                    $output->writeln("<comment>Modo Test: Deteniendo tras generar 1 reporte.</comment>");
                    break;
                }

                $razon = $pdvData['header']['razon_social'];
                $path = $this->pdfGenerator->generatePdvReport($pdvData, $month, $year);
                $generatedFiles[] = $path;
                
                $output->writeln(" -> OK: $razon");
                $i++;
            }

            // 4. Generar Anexo Moura (Solo si no estamos en test simple, o sí, para probar la carátula)
            if ($i > 0) {
                $output->writeln("Generando Carátula y Anexo Moura...");
                $mouraTotals = $this->settlementService->getMouraTotals($month, $year);
                $coverPath = $this->pdfGenerator->generateMouraCover($mouraTotals, $month, $year);
                
                $finalPath = $this->pdfGenerator->generateMouraAnnex($coverPath, $generatedFiles, $month, $year);
                $output->writeln(" -> ANEXO OK: $finalPath");
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln("<error>Error Crítico: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }
    }
}