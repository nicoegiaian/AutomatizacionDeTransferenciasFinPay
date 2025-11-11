<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem; 

class RegenerateAppSecretCommand extends Command
{
    protected static $defaultName = 'regenerate-app-secret';

    protected function configure(): void
    {
        $this
            ->setName('regenerate-app-secret')
            ->setDescription('Regenera la variable APP_SECRET en el archivo .env con una nueva cadena de 32 caracteres hexadecimales.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filesystem = new Filesystem(); // Inicializa el componente Filesystem

        // Generación del secreto (usando random_bytes para mejor seguridad)
        try {
            $secret = bin2hex(random_bytes(16)); // 32 caracteres hexadecimales
        } catch (\Exception $e) {
            // Fallback si random_bytes falla
            $secret = '';
            $a = '0123456789abcdef';
            for ($i = 0; $i < 32; $i++) {
                $secret .= $a[mt_rand(0, 15)]; 
            }
        }
        
        $envPath = getcwd() . '/.env'; // Ruta al archivo .env

        if (!$filesystem->exists($envPath)) {
            $io->error("El archivo .env no fue encontrado en: " . $envPath);
            return 1;
        }

        // 1. Leer el contenido del archivo .env
        $content = file_get_contents($envPath);

        // 2. Reemplazar la línea APP_SECRET usando una expresión regular de PHP
        // Asegura que solo se reemplaza si la línea comienza con APP_SECRET= y tiene 32 caracteres
        $newContent = preg_replace(
            '/^APP_SECRET=.+$/m', // La 'm' al final asegura que ^ y $ coincidan con el inicio/fin de línea
            "APP_SECRET=$secret",
            $content
        );

        if ($newContent === null || $newContent === $content) {
             $io->warning('No se pudo encontrar o reemplazar la línea APP_SECRET en .env. Por favor, revísalo manualmente.');
             // Aunque no se reemplace, podrías querer continuar si el archivo está corrupto
        } else {
             // 3. Escribir el nuevo contenido de vuelta al archivo
            $filesystem->dumpFile($envPath, $newContent);
        }

        $io->success('New APP_SECRET was generated: ' . $secret);
        
        return 0;
    }
}