<?php

namespace App\Form;

use App\Entity\MailList;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MailListType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class)
            ->add('description', TextareaType::class, ['required' => false])
            ->add('firmaHtml', TextareaType::class, ['required' => false])
            ->add('useCustomDsn', CheckboxType::class, ['required' => false])
            ->add('mailerDsnOverride', TextareaType::class, ['required' => false])
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
            ])
            ->add('mailFrom', EmailType::class, ['required' => false])
            ->add('mailFromName', TextType::class, ['required' => false])
            ->add('defaultPrimaryColor', TextType::class, ['required' => false])
            ->add('defaultTextColor', TextType::class, ['required' => false])
            ->add('defaultHeadingFont', TextType::class, ['required' => false])
            ->add('defaultBodyFont', TextType::class, ['required' => false])
            ->add('permettiDisiscrizione', CheckboxType::class, ['required' => false, 'data' => true])
            ->add('unsubscribeText', TextareaType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MailList::class,
            'csrf_protection' => false,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'mail_list';
    }
}
