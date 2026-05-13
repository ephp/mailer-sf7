<?php

namespace App\Command;

use App\Repository\CampaignRepository;
use App\Service\CampaignSenderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:campaign:dispatch-scheduled',
    description: 'Find scheduled campaigns whose time has come and queue them for sending. Run from cron every minute.',
)]
class DispatchScheduledCampaignsCommand extends Command
{
    public function __construct(
        private readonly CampaignRepository $campaignRepository,
        private readonly CampaignSenderService $campaignSenderService,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTime();
        $due = $this->campaignRepository->findScheduledDue($now);

        if (count($due) === 0) {
            $output->writeln('<info>No scheduled campaigns due.</info>');
            return Command::SUCCESS;
        }

        foreach ($due as $campaign) {
            $output->writeln(sprintf(
                '<comment>Dispatching campaign #%d "%s" (scheduled %s)</comment>',
                $campaign->getId(),
                $campaign->getName() ?? '',
                $campaign->getScheduledAt()?->format('Y-m-d H:i') ?? '?',
            ));

            try {
                $this->campaignSenderService->prepareCampaign($campaign);
                $this->campaignSenderService->dispatchAll($campaign);
                $campaign->setStatus('sending');
                $this->em->flush();
                $output->writeln(sprintf('<info>  → dispatched OK</info>'));
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>  → failed: %s</error>', $e->getMessage()));
            }
        }

        return Command::SUCCESS;
    }
}
