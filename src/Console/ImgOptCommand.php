<?php

declare(strict_types=1);

namespace ImgOpt\Console;

use ImgOpt\Capabilities;
use ImgOpt\Config;
use ImgOpt\ImageProcessor;
use ImgOpt\VariantCache;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

#[AsCommand(name: 'img-opt')]
final class ImgOptCommand extends Command
{

    protected function configure(): void
    {
        $this
            ->setName('img-opt')
            ->setDescription('Batch convert PNG/JPG to AVIF + WebP with freshness checks.')
            ->addArgument('folder', InputArgument::REQUIRED, 'Folder to scan')
            ->addOption('max-width', null, InputOption::VALUE_REQUIRED, 'Resize down to width (0 keep original)', '0')
            ->addOption('q-avif', null, InputOption::VALUE_REQUIRED, 'AVIF quality', '42')
            ->addOption('q-webp', null, InputOption::VALUE_REQUIRED, 'WebP quality', '80')
            ->addOption('formats', null, InputOption::VALUE_REQUIRED, 'Comma list: avif,webp', 'avif,webp')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Re-encode even if outputs are fresh')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Show actions without writing files')
            ->addOption('cache-dir', null, InputOption::VALUE_REQUIRED, 'Cache/output directory (default: <folder>/_img-opt)', '')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $folder = (string) $input->getArgument('folder');
        $maxWidth = (int) $input->getOption('max-width');
        $qAvif = (int) $input->getOption('q-avif');
        $qWebp = (int) $input->getOption('q-webp');
        $formats = array_filter(array_map('trim', explode(',', (string) $input->getOption('formats'))));
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');
        $cacheDir = (string) $input->getOption('cache-dir');

        if (!is_dir($folder)) {
            $io->error(sprintf('Folder not found: %s', $folder));
            return Command::FAILURE;
        }

        if ($cacheDir === '') {
            $cacheDir = rtrim($folder, '/') . '/_img-opt';
        }

        $config = Config::fromArray([
            'cache_root' => $cacheDir,
            'max_width' => $maxWidth,
            'quality' => ['avif' => $qAvif, 'webp' => $qWebp],
        ]);
        $caps = new Capabilities();
        $cache = new VariantCache($config->cacheRoot);
        $cache->ensureDirectory();
        $processor = new ImageProcessor($config, $caps);

        $supportedFormats = array_filter($formats, fn ($fmt) => $this->isAllowed($fmt, $caps));
        if (empty($supportedFormats)) {
            $io->warning('No supported formats detected; defaulting to original JPEG/PNG only.');
            $supportedFormats = [];
        }

        $finder = new Finder();
        $finder->files()
            ->in($folder)
            ->ignoreUnreadableDirs()
            ->ignoreVCS(true)
            ->name(['*.png', '*.jpg', '*.jpeg']);

        $total = $finder->count();
        $created = ['avif' => 0, 'webp' => 0];
        $skipped = 0;
        $bad = 0;
        $errors = [];
        $io->progressStart($total);

        foreach ($finder as $file) {
            $io->progressAdvance();
            $src = $file->getRealPath() ?: $file->getPathname();
            if (!is_readable($src)) {
                $bad++;
                continue;
            }

            $mtime = filemtime($src) ?: time();
            foreach ($supportedFormats as $fmt) {
                $quality = $fmt === 'avif' ? $qAvif : $qWebp;
                $targetPath = $cache->getPath($src, $maxWidth > 0 ? $maxWidth : 0, $fmt, $quality);
                $isFresh = file_exists($targetPath) && filemtime($targetPath) >= $mtime;
                if ($isFresh && !$force) {
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $created[$fmt]++;
                    continue;
                }

                try {
                    $processor->generate($src, $maxWidth, $fmt, $targetPath, $quality);
                    $created[$fmt]++;
                } catch (\Throwable $e) {
                    $bad++;
                    if ($output->isVerbose()) {
                        $errors[] = sprintf('%s (%s): %s', $src, $fmt, $e->getMessage());
                    }
                }
            }
        }

        $io->progressFinish();

        $io->writeln('');
        $io->section('Summary');
        $io->listing([
            sprintf('Processed : %d', $total),
            sprintf('Created   : AVIF=%d, WebP=%d', $created['avif'] ?? 0, $created['webp'] ?? 0),
            sprintf('Skipped   : %d', $skipped),
            sprintf('Failed    : %d', $bad),
            sprintf('Cache dir : %s', $cacheDir),
        ]);
        if ($errors !== []) {
            $io->section('Errors (first 10)');
            $io->listing(array_slice($errors, 0, 10));
        }

        return Command::SUCCESS;
    }

    private function isAllowed(string $fmt, Capabilities $caps): bool
    {
        $fmt = strtolower($fmt);
        if ($fmt === 'avif') {
            return $caps->supports('AVIF');
        }
        if ($fmt === 'webp') {
            return $caps->supports('WEBP');
        }
        return in_array($fmt, ['jpeg', 'png', 'jpg'], true);
    }
}
