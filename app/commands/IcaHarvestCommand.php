<?php

namespace Comm\Harvest\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class IcaHarvestCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('harvest:ica')
            ->setDescription('Harvest ica data')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("<info>YEY</info>");
    }

}
