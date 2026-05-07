<?php

namespace App\Form;

use App\Entity\Account;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AccountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('ragioneSociale', TextType::class)
            ->add('emailContatto', EmailType::class)
            ->add('mailFrom', EmailType::class)
            ->add('mailFromName', TextType::class)
            ->add('partitaIva', TextType::class, ['required' => false])
            ->add('indirizzo', TextareaType::class, ['required' => false])
            ->add('mailerDsn', TextType::class, ['required' => false])
            ->add('smtpHost', TextType::class, ['required' => false])
            ->add('smtpPort', IntegerType::class, ['required' => false])
            ->add('smtpUser', TextType::class, ['required' => false])
            ->add('smtpPassword', TextType::class, ['required' => false])
            ->add('smtpEncryption', ChoiceType::class, [
                'required' => false,
                'choices' => [
                    'TLS' => 'tls',
                    'SSL' => 'ssl',
                    'None' => 'none',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Account::class,
            'csrf_protection' => false,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'account';
    }
}
