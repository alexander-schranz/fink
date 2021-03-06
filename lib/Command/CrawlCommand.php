<?php

namespace DTL\Extension\Fink\Command;

use Amp\Loop;
use DTL\Extension\Fink\Model\DispatcherBuilderFactory;
use DTL\Extension\Fink\Model\Dispatcher;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use DTL\Extension\Fink\Command\Exception\AtLeastOneFailure;

class CrawlCommand extends Command
{
    const ARG_URL = 'url';

    const OPT_CONCURRENCY = 'concurrency';
    const OPT_DESCENDANTS_ONLY = 'descendants-only';
    const OPT_MAX_DISTANCE = 'max-distance';
    const OPT_NO_DEDUPE = 'no-dedupe';

    const DISPLAY_POLL_TIME = 100;
    const RUNNER_POLL_TIME = 10;

    const EXIT_STATUS_FAILURE = 2;
    const EXIT_STATUS_SUCCESS = 0;

    const OPT_OUTPUT = 'output';
    const OPT_INSECURE = 'insecure';
    const OPT_LOAD_COOKIES = 'load-cookies';

    /**
     * @var DispatcherBuilderFactory
     */
    private $factory;

    public function __construct(DispatcherBuilderFactory $factory)
    {
        parent::__construct();
        $this->factory = $factory;
    }

    protected function configure()
    {
        $this->setDescription('Crawl the given URL');

        $this->addArgument(self::ARG_URL, InputArgument::REQUIRED, 'URL to crawl');

        $this->addOption(self::OPT_CONCURRENCY, 'c', InputOption::VALUE_REQUIRED, 'Concurrency', 10);
        $this->addOption(self::OPT_OUTPUT, 'o', InputOption::VALUE_REQUIRED, 'Output file');
        $this->addOption(self::OPT_NO_DEDUPE, 'D', InputOption::VALUE_NONE, 'Do not de-duplicate URLs');
        $this->addOption(self::OPT_DESCENDANTS_ONLY, 'l', InputOption::VALUE_NONE, 'Only crawl descendants of the given path');
        $this->addOption(self::OPT_INSECURE, 'k', InputOption::VALUE_NONE, 'Allow insecure server connections with SSL');
        $this->addOption(self::OPT_MAX_DISTANCE, 'm', InputOption::VALUE_REQUIRED, 'Maximum link distance from base URL');
        $this->addOption(self::OPT_LOAD_COOKIES, null, InputOption::VALUE_REQUIRED, 'Load cookies from file');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        assert($output instanceof ConsoleOutput);

        $dispatcher = $this->buildDispatcher($input);

        Loop::repeat(self::RUNNER_POLL_TIME, function () use ($dispatcher) {
            $dispatcher->dispatch();
        });

        $section = $output->section();

        Loop::repeat(self::DISPLAY_POLL_TIME, function () use ($section, $dispatcher) {
            $status = $dispatcher->status();
            $section->overwrite(sprintf(
                '<comment>Concurrency</>: %s, <comment>URL queue size</>: %s, <comment>Failures</>: %s/%s (%s%%)' . PHP_EOL .
                '%s',
                $status->nbConcurrentRequests,
                $status->queueSize,
                $status->nbFailures,
                $status->requestCount,
                number_format($dispatcher->status()->failurePercentage(), 2),
                $status->lastUrl
            ));

            if ($status->nbConcurrentRequests === 0 && $status->queueSize === 0) {
                Loop::stop();

                if ($dispatcher->status()->nbFailures) {
                    throw new AtLeastOneFailure();
                }
            }
        });

        try {
            Loop::run();
        } catch (AtLeastOneFailure $e) {
            return self::EXIT_STATUS_FAILURE;
        }

        return self::EXIT_STATUS_SUCCESS;
    }

    private function buildDispatcher(InputInterface $input): Dispatcher
    {
        $url = $this->castToString($input->getArgument('url'));
        
        $maxConcurrency = $this->castToInt($input->getOption(self::OPT_CONCURRENCY));
        $outfile = $input->getOption(self::OPT_OUTPUT);
        $noDedupe = $this->castToBool($input->getOption(self::OPT_NO_DEDUPE));
        $descendantsOnly = $this->castToBool($input->getOption(self::OPT_DESCENDANTS_ONLY));
        $insecure = $this->castToBool($input->getOption(self::OPT_INSECURE));
        $maxDistance = $input->getOption(self::OPT_MAX_DISTANCE);
        $cookieFile = $input->getOption(self::OPT_LOAD_COOKIES);
        
        $builder = $this->factory->createForUrl($url);
        $builder->maxConcurrency($maxConcurrency);
        $builder->noDeduplication($noDedupe);
        $builder->descendantsOnly($descendantsOnly);
        $builder->noPeerVerification($insecure);
        if ($outfile) {
            $builder->publishTo($this->castToString($outfile));
        }
        if ($cookieFile) {
            $builder->loadCookies($this->castToString($cookieFile));
        }

        if (null !== $maxDistance) {
            $builder->maxDistance($this->castToInt($maxDistance));
        }

        return $builder->build();
    }

    private function castToInt($value): int
    {
        if (!is_numeric($value)) {
            throw new RuntimeException(sprintf(
                'value was not an int, got "%s"',
                var_export($value, true)
            ));
        }

        return (int) $value;
    }

    private function castToString($value): string
    {
        if (!is_string($value)) {
            throw new RuntimeException(sprintf(
                'value was not a string'
            ));
        }

        return $value;
    }

    private function castToBool($value): bool
    {
        if (!is_bool($value)) {
            throw new RuntimeException(sprintf(
                'value was not a bool'
            ));
        }

        return $value;
    }
}
