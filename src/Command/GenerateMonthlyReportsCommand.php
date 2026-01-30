<?php

namespace App\Command;

use App\Service\Report\MonthlySettlementService;
use App\Service\Report\PdfReportGenerator;
use App\Service\Notifier; 
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:generate-monthly-reports',
    description: 'Genera reportes PDF mensuales y los maestros por Unidad de Negocio y Global.',
)]
class GenerateMonthlyReportsCommand extends Command
{
    private MonthlySettlementService $settlementService;
    private PdfReportGenerator $pdfGenerator;
    private Notifier $notifier;
    private string $logFilePath;

    public function __construct(
        MonthlySettlementService $settlementService, 
        PdfReportGenerator $pdfGenerator,
        Notifier $notifier, 
        #[Autowire('%kernel.project_dir%')] string $projectDir
    )
    {
        $this->settlementService = $settlementService;
        $this->pdfGenerator = $pdfGenerator;
        $this->notifier = $notifier;
        $this->logFilePath = $projectDir . '/var/log/reporte_mensual_moura.log';
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('month', null, InputOption::VALUE_OPTIONAL, 'Mes (1-12)')
            ->addOption('year', null, InputOption::VALUE_OPTIONAL, 'Año (ej: 2025)')
            ->addOption('test-one', null, InputOption::VALUE_NONE, 'Si se activa, solo genera 1 reporte individual para probar');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // 1. INICIALIZAR LOG
        file_put_contents($this->logFilePath, "=== INICIO DE PROCESO ===\n");
        
        $log = function($msg, $type = 'INFO') use ($output) {
            $timestamp = date('Y-m-d H:i:s');
            $line = "[$timestamp] [$type] $msg";
            file_put_contents($this->logFilePath, $line . PHP_EOL, FILE_APPEND);
            
            if ($type === 'ERROR' || $type === 'CRITICAL') {
                $output->writeln("<error>$line</error>");
            } else {
                $output->writeln($line);
            }
        };

        $now = new \DateTime();
        $month = $input->getOption('month') ? (int)$input->getOption('month') : (int)$now->modify('-1 month')->format('n');
        $year = $input->getOption('year') ? (int)$input->getOption('year') : (int)$now->format('Y');

        $log("Iniciando generación para $month/$year");

        // --- CORRECCIÓN: Inicializamos la lista ANTES de empezar ---
        $archivosFinales = []; 

        try {
            // --- PASO 1: TRAER DATOS ---
            $log("Consultando datos de Puntos de Venta...");
            $data = $this->settlementService->getMonthlyData($month, $year);
            $count = count($data);
            $log("Comercios encontrados: $count");

            if ($count === 0) {
                $log("No hay datos para procesar. Fin del proceso.", 'WARNING');
                return Command::SUCCESS;
            }

            $log("Consultando resúmenes de Moura (Global y Unidades)...");
            $mouraSummaries = $this->settlementService->getMouraSummaries($month, $year);

            // --- PASO 2: GENERAR INDIVIDUALES ---
            $pdvFilesMap = [];    
            $limit = $input->getOption('test-one') ? 1 : 999999;
            $i = 0;

            foreach ($data as $pdvData) {
                if ($i >= $limit) {
                    $log("Modo Test: Deteniendo generación individual.", 'WARNING');
                    break;
                }
                $razon = $pdvData['header']['razon_social'];
                try {
                    $path = $this->pdfGenerator->generatePdvReport($pdvData, $month, $year);
                    $unidad = $pdvData['header']['unidad_de_negocio'] ?? 'Sin Unidad';
                    
                    if (!isset($pdvFilesMap[$unidad])) $pdvFilesMap[$unidad] = [];
                    $pdvFilesMap[$unidad][] = $path;
                    
                    // --- CORRECCIÓN: Agregamos el archivo a la lista ---
                    $archivosFinales[] = "PDV [$unidad]: " . basename($path);
                    
                    $log(" -> OK PDF Individual [$unidad]: $razon");
                } catch (\Throwable $ePdv) {
                    $log("Fallo generando PDF de $razon: " . $ePdv->getMessage(), 'ERROR');
                }
                $i++;
            }

            // --- PASO 3: GENERAR MAESTROS ---
            $unitMasterFiles = [];

            if ($i > 0) {
                $log("=== Generando Archivos Maestros ===");
                
                foreach ($mouraSummaries['units'] as $unitName => $unitData) {
                    $filesForUnit = $pdvFilesMap[$unitName] ?? [];
                    if (empty($filesForUnit)) continue;

                    try {
                        $unitPath = $this->pdfGenerator->generateUnitMaster($unitName, $unitData, $filesForUnit, $month, $year);
                        $log(" -> UNIDAD GENERADA [$unitName]: " . basename($unitPath));
                        
                        // Agregamos con negrita para destacar
                        $archivosFinales[] = "<strong>UNIDAD [$unitName]: " . basename($unitPath) . "</strong>";
                        $unitMasterFiles[] = $unitPath;
                    } catch (\Throwable $eUnit) { 
                        $log("Fallo generando Unidad $unitName: " . $eUnit->getMessage(), 'ERROR');
                    }
                }

                try {
                    $globalPath = $this->pdfGenerator->generateGlobalMaster($mouraSummaries['global'], $unitMasterFiles, $month, $year);
                    $log(" -> GLOBAL GENERADO: " . basename($globalPath));
                    
                    // Ponemos el Global primero
                    array_unshift($archivosFinales, "<strong>GLOBAL COMPLETO: " . basename($globalPath) . "</strong>");
                } catch (\Throwable $eGlobal) { 
                    $log("Fallo generando Global: " . $eGlobal->getMessage(), 'ERROR');
                }
            }

            $log("=== PROCESO FINALIZADO OK ===");

            // --- EMAIL DE ÉXITO ---
            $listaHTML = "<ul><li>" . implode("</li><li>", $archivosFinales) . "</li></ul>";
            $htmlBody = "<h3>Reportes Moura $month/$year: Generación Exitosa</h3>
                            <p>El proceso finalizó correctamente. Se han generado " . count($archivosFinales) . " archivos:</p>
                            $listaHTML
                            <p><small>Log de ejecución disponible en el servidor.</small></p>";
            
            $this->notifier->sendHtmlEmail(
                "Reportes Moura $month/$year: Generación Exitosa", 
                $htmlBody
            );
            
            $log("Notificación de éxito enviada.");

            return Command::SUCCESS;

        } catch (\Throwable $e) { 
            
            $msg = "Error Crítico Generando Reportes: " . $e->getMessage();
            $log($msg, 'CRITICAL');
            $log($e->getTraceAsString(), 'CRITICAL');

            $this->notifier->sendFailureEmail(
                "ALERTA: Falla Automatización Moura", 
                "El proceso falló. Se adjunta log de ejecución.\n\nError: " . $e->getMessage(),
                $this->logFilePath
            );
            
            $output->writeln("Alerta de fallo enviada.");

            return Command::FAILURE;
        }
    }
}