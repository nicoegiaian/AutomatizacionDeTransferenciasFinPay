<?php

namespace App\Command;

use App\Service\MuteSettlementService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:mute-monthly-report',
    description: 'Genera y envía el reporte mensual acumulado de Mute.',
)]
class MuteMonthlyReportCommand extends Command
{
    private MuteSettlementService $muteService;

    public function __construct(MuteSettlementService $muteService)
    {
        $this->muteService = $muteService;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('month', null, InputOption::VALUE_OPTIONAL, 'Mes del reporte (número 1-12)')
            ->addOption('year', null, InputOption::VALUE_OPTIONAL, 'Año del reporte (ej. 2025)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Iniciando generación de reporte mensual Mute...');
        
        $month = $input->getOption('month');
        $year = $input->getOption('year');

        // Conversión a enteros si vienen argumentos
        $m = $month ? (int)$month : null;
        $y = $year ? (int)$year : null;

        try {
            $this->muteService->generateMonthlyReport($m, $y);
            $output->writeln('Reporte generado y enviado con éxito.');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('Error al generar reporte: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}