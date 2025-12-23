<?php

namespace App\Command;

use App\Service\MuteSettlementService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:mute-settlement',
    description: 'Ejecuta el proceso de liquidación y división de fondos Mute.',
)]
class MuteSettlementCommand extends Command
{
    private MuteSettlementService $muteService;

    public function __construct(MuteSettlementService $muteService)
    {
        $this->muteService = $muteService;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Iniciando proceso Mute Settlement...');
        
        try {
            $this->muteService->executeSettlement();
            $output->writeln('Proceso finalizado.');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('Error crítico: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}