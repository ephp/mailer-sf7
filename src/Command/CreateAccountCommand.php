<?php

namespace App\Command;

use App\Entity\Account;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:account:create',
    description: 'Crea un nuovo Account e opzionalmente un nuovo utente associato',
)]
class CreateAccountCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('user-id', null, InputOption::VALUE_REQUIRED, 'ID utente esistente a cui associare l\'account');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Creazione nuovo Account');

        $userId = $input->getOption('user-id');
        $user = null;
        $newUserEmail = null;
        $newUserPassword = null;

        if ($userId !== null) {
            $user = $this->em->getRepository(User::class)->find((int) $userId);
            if ($user === null) {
                $io->error(sprintf('Utente con ID %s non trovato.', $userId));
                return Command::FAILURE;
            }
            if ($user->getAccount() !== null) {
                $io->error(sprintf('L\'utente %s ha già un account associato (ID %d).', $user->getEmail(), $user->getAccount()->getId()));
                return Command::FAILURE;
            }
            $io->text(sprintf('Utente trovato: %s %s <%s>', $user->getFirstName(), $user->getLastName(), $user->getEmail()));
        } else {
            $io->section('Dati nuovo utente');
            $firstName = $this->ask($io, 'Nome');
            $lastName = $this->ask($io, 'Cognome');
            $newUserEmail = $this->askEmail($io, 'Email utente');

            $existingUser = $this->em->getRepository(User::class)->findOneBy(['email' => $newUserEmail]);
            if ($existingUser !== null) {
                $io->error(sprintf('Esiste già un utente con email %s.', $newUserEmail));
                return Command::FAILURE;
            }

            $newUserPassword = $this->askPassword($io);

            $user = new User();
            $user->setFirstName($firstName);
            $user->setLastName($lastName);
            $user->setEmail($newUserEmail);
            $user->setDisabled(false);
            $user->addRole('ROLE_ADMIN');
            $user->setPassword($this->passwordHasher->hashPassword($user, $newUserPassword));
        }

        $io->section('Dati Account');
        $ragioneSociale = $this->ask($io, 'Ragione Sociale');
        $emailContatto = $this->askEmail($io, 'Email di contatto');
        $mailFrom = $this->askEmail($io, 'Email mittente (apparirà come From)');
        $mailFromName = $this->ask($io, 'Nome mittente (apparirà accanto al From)');
        $mailerDsn = $this->askOptional($io, 'Mailer DSN (lascia vuoto per configurare in seguito)');

        $account = new Account();
        $account->setRagioneSociale($ragioneSociale);
        $account->setEmailContatto($emailContatto);
        $account->setMailFrom($mailFrom);
        $account->setMailFromName($mailFromName);
        if ($mailerDsn !== null) {
            $account->setMailerDsn($mailerDsn);
        }

        $user->setAccount($account);

        $this->em->persist($account);
        $this->em->persist($user);
        $this->em->flush();

        $io->success('Account creato con successo!');

        $rows = [
            ['Account ID', (string) $account->getId()],
            ['Ragione Sociale', $account->getRagioneSociale()],
            ['Email Contatto', $account->getEmailContatto()],
            ['Email Mittente', $account->getMailFrom()],
            ['Nome Mittente', $account->getMailFromName()],
            ['Mailer DSN', $account->getMailerDsn() ?? '(non configurato)'],
            ['API Key', $account->getApiKey()],
        ];

        if ($newUserEmail !== null) {
            $rows[] = ['---', '---'];
            $rows[] = ['Utente ID', (string) $user->getId()];
            $rows[] = ['Email Utente', $newUserEmail];
            $rows[] = ['Password', $newUserPassword];
        } else {
            $rows[] = ['Utente associato', sprintf('%s <%s>', $user->getFirstName() . ' ' . $user->getLastName(), $user->getEmail())];
        }

        $io->table(['Campo', 'Valore'], $rows);

        return Command::SUCCESS;
    }

    private function ask(SymfonyStyle $io, string $label): string
    {
        $question = new Question("{$label}: ");
        $question->setValidator(function (?string $answer) use ($label): string {
            if (empty(trim((string) $answer))) {
                throw new \RuntimeException("{$label} non può essere vuoto.");
            }
            return trim($answer);
        });
        return $io->askQuestion($question);
    }

    private function askEmail(SymfonyStyle $io, string $label): string
    {
        $question = new Question("{$label}: ");
        $question->setValidator(function (?string $answer) use ($label): string {
            $value = trim((string) $answer);
            if (empty($value)) {
                throw new \RuntimeException("{$label} non può essere vuoto.");
            }
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException("{$label}: formato email non valido.");
            }
            return $value;
        });
        return $io->askQuestion($question);
    }

    private function askOptional(SymfonyStyle $io, string $label): ?string
    {
        $question = new Question("{$label}: ");
        $value = $io->askQuestion($question);
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    private function askPassword(SymfonyStyle $io): string
    {
        $question = new Question('Password utente: ');
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $question->setValidator(function (?string $answer): string {
            $value = trim((string) $answer);
            if (empty($value)) {
                throw new \RuntimeException('La password non può essere vuota.');
            }
            if (strlen($value) < 6) {
                throw new \RuntimeException('La password deve avere almeno 6 caratteri.');
            }
            return $value;
        });
        return $io->askQuestion($question);
    }
}
