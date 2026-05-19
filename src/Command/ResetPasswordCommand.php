<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:reset:password',
    description: 'Reset the password of an existing user (interactive)',
)]
class ResetPasswordCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::OPTIONAL, 'Email of the user whose password must be reset')
            ->addArgument('password', InputArgument::OPTIONAL, 'New password')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $this->getEmailOrAsk($input, $io);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Invalid email format');
            return Command::FAILURE;
        }

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $io->error(sprintf('No user found with email "%s"', $email));
            return Command::FAILURE;
        }

        $password = $this->getPasswordOrAsk($input, $io);

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->em->flush();

        $io->success(sprintf('Password successfully reset for user "%s"', $email));

        return Command::SUCCESS;
    }

    private function getEmailOrAsk(InputInterface $input, SymfonyStyle $io): string
    {
        $email = $input->getArgument('email');

        if (empty($email)) {
            $question = new Question('Enter Email: ');
            $question->setValidator(function ($answer) {
                if (empty(trim($answer))) {
                    throw new \RuntimeException('Email cannot be empty');
                }
                return trim($answer);
            });

            $email = $io->askQuestion($question);
        }

        return $email;
    }

    private function getPasswordOrAsk(InputInterface $input, SymfonyStyle $io): string
    {
        $password = $input->getArgument('password');

        if (empty($password)) {
            $question = new Question('Enter new Password: ');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $question->setValidator(function ($answer) {
                if (empty(trim($answer))) {
                    throw new \RuntimeException('Password cannot be empty');
                }
                if (strlen(trim($answer)) < 6) {
                    throw new \RuntimeException('Password must be at least 6 characters long');
                }
                return trim($answer);
            });

            $password = $io->askQuestion($question);
        }

        return $password;
    }
}
