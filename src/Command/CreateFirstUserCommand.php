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
use Symfony\Component\Console\Question\Question;

#[AsCommand(
    name: 'app:create:user',
    description: 'Create a new user with interactive wizard or default Oimmei values',
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
            ->addOption('oimmei', null, InputOption::VALUE_NONE, 'Use default Oimmei values')
            ->addArgument('firstname', InputArgument::OPTIONAL, 'First Name')
            ->addArgument('lastname', InputArgument::OPTIONAL, 'Last Name')
            ->addArgument('email', InputArgument::OPTIONAL, 'Email')
            ->addArgument('password', InputArgument::OPTIONAL, 'Password')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Se è presente l'opzione --oimmei, usa i valori di default
        if ($input->getOption('oimmei')) {
            $firstname = 'Oimmei';
            $lastname = 'D.C.';
            $email = 'info@oimmei.com';
            $password = 'Oimmei^_';
            $role = 'ROLE_OIMMEI';
        } else {
            // Altrimenti usa il wizard per raccogliere i dati
            $firstname = $this->getValueOrAsk($input, $io, 'firstname', 'First Name');
            $lastname = $this->getValueOrAsk($input, $io, 'lastname', 'Last Name');
            $email = $this->getValueOrAsk($input, $io, 'email', 'Email');
            $password = $this->getPasswordOrAsk($input, $io);
            $role = 'ROLE_ADMIN';
        }

        // Validazione base dell'email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Invalid email format');
            return Command::FAILURE;
        }

        // Controlla se l'utente esiste già
        $existingUser = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existingUser) {
            $io->error('A user with this email already exists');
            return Command::FAILURE;
        }

        $user = new User();

        $user->setFirstName($firstname);
        $user->setLastName($lastname);
        $user->setEmail($email);
        $user->setDisabled(false);
        $user->addRole($role);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        $io->success("The user was successfully created. You can login with: " . $email . " / " . $password);

        return Command::SUCCESS;
    }

    private function getValueOrAsk(InputInterface $input, SymfonyStyle $io, string $argumentName, string $label): string
    {
        $value = $input->getArgument($argumentName);

        if (empty($value)) {
            $question = new Question("Enter {$label}: ");
            $question->setValidator(function ($answer) use ($label) {
                if (empty(trim($answer))) {
                    throw new \RuntimeException("{$label} cannot be empty");
                }
                return trim($answer);
            });

            $value = $io->askQuestion($question);
        }

        return $value;
    }

    private function getPasswordOrAsk(InputInterface $input, SymfonyStyle $io): string
    {
        $password = $input->getArgument('password');

        if (empty($password)) {
            $question = new Question('Enter Password: ');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $question->setValidator(function ($answer) {
                if (empty(trim($answer))) {
                    throw new \RuntimeException("Password cannot be empty");
                }
                if (strlen(trim($answer)) < 6) {
                    throw new \RuntimeException("Password must be at least 6 characters long");
                }
                return trim($answer);
            });

            $password = $io->askQuestion($question);
        }

        return $password;
    }
}
