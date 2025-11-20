<?php
// src/Command/DebinMonitorCommand.php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\TransferManager; 
use App\Service\Notifier; 

#[AsCommand(
    name: 'app:bind:monitor-debin',
    description: 'Consulta periódicamente el estado de los DEBIN PULLs pendientes y dispara transferencias PUSH si están acreditados.',
)]
class DebinMonitorCommand extends Command
{
    private TransferManager $transferManager;
    private Notifier $notifier;

    // Inyección de dependencias para el Job
    public function __construct(TransferManager $transferManager, Notifier $notifier)
    {
        $this->transferManager = $transferManager;
        $this->notifier = $notifier;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Iniciando monitoreo de DEBINs pendientes...</info>');

        // 1. Obtener la lista de DEBINs pendientes de monitorear
        // Asumo un método en TransferManager que trae DEBINs con estado 'PENDING' o 'EN_PROCESO'
        $debinPendientes = $this->transferManager->getPendingDebins();
        
        if (empty($debinPendientes)) {
            $output->writeln('No hay DEBINs pendientes de monitoreo. Finalizando.');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Encontrados %d DEBINs pendientes para procesar.', count($debinPendientes)));

        // 2. Iterar y procesar cada DEBIN
        foreach ($debinPendientes as $debin) {
            $comprobanteId = $debin['id_comprobante_bind'];
            $output->writeln("-> Procesando DEBIN ID: {$comprobanteId}");

            try {
                // 3. Consultar el estado en BIND
                $statusData = $this->transferManager->checkDebinStatus($comprobanteId);
                $estadoBind = $statusData['estado'];

                // 4. Lógica de Control y Decisión
                switch ($estadoBind) {
                    case 'COMPLETED':
                        $output->writeln("<fg=green>DEBIN ID {$comprobanteId} COMPLETED. Iniciando Transferencias PUSH...</>");
                        // Disparar las transferencias PUSH a los PDVs (Etapa 2)
                        $this->transferManager->processPdvTransfers($debin); 
                        break;
                        
                    case 'PENDING':
                    case 'IN_PROGRESS':
                        $output->writeln("DEBIN ID {$comprobanteId} aún en proceso. Esperando...");
                        break;
                        
                    case 'UNKNOWN':
                    case 'UNKNOWN_FOREVER':
                        // Fallo Terminal Lógico: Notificar y marcar en BD.
                        $output->writeln("<error>FALLO LÓGICO: Estado UNKNOWN para {$comprobanteId}. Enviando alerta.</error>");
                        $this->notifier->sendFailureEmail("ALERTA BIND: Fallo Terminal DEBIN", "Estado: {$estadoBind}. ID: {$comprobanteId}");
                        $this->transferManager->markDebinAsFailed($debin, $estadoBind);
                        break;

                    default:
                        $output->writeln("<comment>Estado inesperado: {$estadoBind} para {$comprobanteId}. Marcando como fallo.</comment>");
                        $this->notifier->sendFailureEmail("ALERTA BIND: Estado Inesperado", "Estado: {$estadoBind}. ID: {$comprobanteId}");
                        $this->transferManager->markDebinAsFailed($debin, $estadoBind);
                }

            } catch (\Exception $e) {
                // Fallo de Conexión o Error 4xx/5xx del BindService
                $output->writeln("<error>FALLO CRÍTICO DE CONEXIÓN/API para {$comprobanteId}: {$e->getMessage()}</error>");
                $this->notifier->sendFailureEmail("FALLO CRÍTICO CONEXIÓN BIND", "Error: {$e->getMessage()}. ID: {$comprobanteId}");
                // No marcamos como fallido, solo notificamos, para que el job reintente la conexión en el próximo ciclo.
            }
        }
        
        $output->writeln('<info>Monitoreo finalizado.</info>');
        return Command::SUCCESS;
    }
}