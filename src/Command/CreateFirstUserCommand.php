<?php

namespace App\Command;

use App\Entity\Operator;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create:user',
    description: 'Add a short description for your command',
)]
class CreateFirstUserCommand extends Command
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
            ->addArgument('firstname', InputArgument::OPTIONAL, 'First Name', 'Oimmei')
            ->addArgument('lastname', InputArgument::OPTIONAL, 'Last Name', 'D.C.')
            ->addArgument('email', InputArgument::OPTIONAL, 'Email', 'info@oimmei.com')
            ->addArgument('password', InputArgument::OPTIONAL, 'Password', 'Oimmei^_')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $firstname = $input->getArgument('firstname');
        $lastname = $input->getArgument('lastname');
        $email = $input->getArgument('email');
        $password = $input->getArgument('password');

        $user = new User();

        $user->setFirstName($firstname);
        $user->setLastName($lastname);
        $user->setEmail($email);
        $user->setDisabled(false);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        $io->success("The user was successfully created. You can login with: " . $email . " / " . $password);

        return Command::SUCCESS;
    }
}
