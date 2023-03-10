<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) EC-CUBE CO.,LTD. All Rights Reserved.
 *
 * http://www.ec-cube.co.jp/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Eccube\Tests\Service;

use Eccube\Entity\BaseInfo;
use Eccube\Entity\Customer;
use Eccube\Entity\Shipping;
use Eccube\Repository\MailHistoryRepository;
use Eccube\Service\MailService;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\HttpFoundation\Request;

/**
 * MailService test cases.
 */
class MailServiceTest extends AbstractServiceTestCase
{
    use MailerAssertionsTrait;

    /**
     * @var Customer
     */
    protected $Customer;

    /**
     * @var Order
     */
    protected $Order;
    /**
     * @var BaseInfo
     */
    protected $BaseInfo;

    /**
     * @var MailService
     */
    protected $mailService;

    /**
     * @var OrderRepository
     */
    protected $orderRepository;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->Customer = $this->createCustomer();
        $this->Order = $this->createOrder($this->Customer);
        $this->BaseInfo = $this->entityManager->find(BaseInfo::class, 1);
        $this->mailService = static::getContainer()->get(MailService::class);

        $request = Request::createFromGlobals();
        static::getContainer()->get('request_stack')->push($request);
        $twig = static::getContainer()->get('twig');
        $twig->addGlobal('BaseInfo', $this->BaseInfo);
    }

    public function testSendCustomerConfirmMail()
    {
        $url = 'http://example.com/confirm';
        $this->mailService->sendCustomerConfirmMail($this->Customer, $url);

        $this->assertEmailCount(1);
        /** @var Email $Message */
        $Message = $this->getMailerMessage(0);

        $this->assertEmailTextBodyContains($Message, $url, 'URL???'.$url.'?????????????????????');

        $this->expected = '['.$this->BaseInfo->getShopName().'] ????????????????????????';
        $this->actual = $Message->getSubject();
        $this->verify();

        $this->expected = $this->Customer->getEmail();
        $this->actual = $Message->getTo()[0]->getAddress();
        $this->verify();

        $this->expected = $this->BaseInfo->getEmail03();
        $this->actual = $Message->getReplyTo()[0]->getAddress();
        $this->verify();

        $this->expected = $this->BaseInfo->getEmail01();
        $this->actual = $Message->getBcc()[0]->getAddress();
        $this->verify();

        $this->assertEmailHtmlBodyContains($Message, $url, 'URL???'.$url.'?????????????????????');
    }

    public function testSendCustomerCompleteMail()
    {
        $this->mailService->sendCustomerCompleteMail($this->Customer);

        $this->assertEmailCount(1);
        /** @var Email $Message */
        $Message = $this->getMailerMessage(0);

        $this->expected = '['.$this->BaseInfo->getShopName().'] ????????????????????????????????????';
        $this->actual = $Message->getSubject();
        $this->verify();

        $this->expected = $this->Customer->getEmail();
        $this->actual = $Message->getTo()[0]->getAddress();
        $this->verify();

        $this->expected = $this->BaseInfo->getEmail03();
        $this->actual = $Message->getReplyTo()[0]->getAddress();
        $this->verify();

        $this->expected = $this->BaseInfo->getEmail01();
        $this->actual = $Message->getBcc()[0]->getAddress();
        $this->verify();

        $this->assertEmailTextBodyContains($Message, '??????????????????????????????????????????');
        $this->assertEmailHtmlBodyContains($Message, '??????????????????????????????????????????');
    }

    public function testSendCustomerWithdrawMail()
    {
        $email = 'draw@example.com';
        $this->mailService->sendCustomerWithdrawMail($this->Customer, $email);

        $this->assertEmailCount(1);
        /** @var Email $Message */
        $Message = $this->getMailerMessage(0);

        $this->expected = '['.$this->BaseInfo->getShopName().'] ???????????????????????????';
        $this->actual = $Message->getSubject();
        $this->verify();

        $this->expected = $email;
        $this->actual = $Message->getTo()[0]->getAddress();
        $this->verify();

        $this->expected = $this->BaseInfo->getEmail03();
        $this->actual = $Message->getReplyTo()[0]->getAddress();
        $this->verify();

        $this->expected = $this->BaseInfo->getEmail01();
        $this->actual = $Message->getBcc()[0]->getAddress();
        $this->verify();

        $this->assertEmailTextBodyContains($Message, '??????????????????????????????????????????');
        $this->assertEmailHtmlBodyNotContains($Message, '??????????????????????????????????????????', 'HTML part ??????????????????');
    }

    public function testSendContactMail()
    {
        $faker = $this->getFaker();
        $name01 = $faker->lastName;
        $name02 = $faker->firstName;
        $kana01 = $faker->lastName;
        $kana02 = $faker->firstName;
        $email = $faker->email;
        $postCode = $faker->postCode;
        $Pref = $this->entityManager->find(\Eccube\Entity\Master\Pref::class, 1);
        $addr01 = $faker->city;
        $addr02 = $faker->streetAddress;

        $formData = [
            'name01' => $name01,
            'name02' => $name02,
            'kana01' => $kana01,
            'kana02' => $kana02,
            'postal_code' => $postCode,
            'pref' => $Pref,
            'addr01' => $addr01,
            'addr02' => $addr02,
            'phone_number' => $faker->phoneNumber,
            'email' => $email,
            'contents' => '????????????????????????',
        ];

        $this->mailService->sendContactMail($formData);

        $this->assertEmailCount(1);
        /** @var Email $Message */
        $Message = $this->getMailerMessage(0);

        $this->expected = '['.$this->BaseInfo->getShopName().'] ?????????????????????????????????????????????';
        $this->actual = $Message->getSubject();
        $this->verify();

        $this->expected = $email;
        $this->actual = $Message->getTo()[0]->getAddress();
        $this->verify();

        $this->expected = $this->BaseInfo->getEmail03();
        $this->actual = $Message->getReplyTo()[0]->getAddress();
        $this->verify();

        $this->expected = $this->BaseInfo->getEmail01();
        $this->actual = $Message->getBcc()[0]->getAddress();

        $this->assertEmailTextBodyContains($Message, '????????????????????????');
        $this->assertEmailHtmlBodyContains($Message, '????????????????????????');
    }

    public function testSendOrderMail()
    {
        $Order = $this->Order;
        $this->mailService->sendOrderMail($Order);

        $this->assertEmailCount(1);
        /** @var Email $Message */
        $Message = $this->getMailerMessage(0);

        $this->expected = '['.$this->BaseInfo->getShopName().'] ???????????????????????????????????????';
        $this->actual = $Message->getSubject();
        $this->verify();

        $this->expected = $Order->getEmail();
        $this->actual = $Message->getTo()[0]->getAddress();
        $this->verify();

        $this->expected = $this->BaseInfo->getEmail03();
        $this->actual = $Message->getReplyTo()[0]->getAddress();
        $this->verify();

        $this->expected = $this->BaseInfo->getEmail01();
        $this->actual = $Message->getBcc()[0]->getAddress();
        $this->verify();

        $this->assertEmailTextBodyContains($Message, '????????????????????????????????????????????????????????????????????????');
        $this->assertEmailHtmlBodyContains($Message, '????????????????????????????????????????????????????????????????????????');
    }

    public function testSendAdminCustomerConfirmMail()
    {
        $url = 'http://example.com/confirm';
        $this->mailService->sendAdminCustomerConfirmMail($this->Customer, $url);

        $this->assertEmailCount(1);
        /** @var Email $Message */
        $Message = $this->getMailerMessage(0);

        $this->assertEmailTextBodyContains($Message, $url, 'URL???'.$url.'?????????????????????');
        $this->assertEmailHtmlBodyContains($Message, $url, 'URL???'.$url.'?????????????????????');
        $this->expected = '['.$this->BaseInfo->getShopName().'] ????????????????????????';
        $this->actual = $Message->getSubject();
        $this->verify();

        $this->expected = $this->Customer->getEmail();
        $this->actual = $Message->getTo()[0]->getAddress();
        $this->verify();

        $this->expected = $this->BaseInfo->getEmail03();
        $this->actual = $Message->getReplyTo()[0]->getAddress();
        $this->verify();

        $this->expected = $this->BaseInfo->getEmail01();
        $this->actual = $Message->getBcc()[0]->getAddress();
        $this->verify();
    }

    public function testSendAdminOrderMail()
    {
        $Order = $this->Order;
        $faker = $this->getFaker();
        $subject = $faker->sentence;
        $formData = [
            'mail_subject' => $subject,
            'tpl_data' => $faker->text,
        ];
        $this->mailService->sendAdminOrderMail($Order, $formData);

        $this->assertEmailCount(1);
        /** @var Email $Message */
        $Message = $this->getMailerMessage(0);

        $this->expected = '['.$this->BaseInfo->getShopName().'] '.$subject;
        $this->actual = $Message->getSubject();
        $this->verify();

        $this->expected = $Order->getEmail();
        $this->actual = $Message->getTo()[0]->getAddress();
        $this->verify();

        $this->expected = $this->BaseInfo->getEmail03();
        $this->actual = $Message->getReplyTo()[0]->getAddress();
        $this->verify();

        $this->expected = $this->BaseInfo->getEmail01();
        $this->actual = $Message->getBcc()[0]->getAddress();
        $this->verify();
    }

    public function testSendPasswordResetNotificationMail()
    {
        $url = 'http://example.com/reset';
        $this->mailService->sendPasswordResetNotificationMail($this->Customer, $url);

        $this->assertEmailCount(1);
        /** @var Email $Message */
        $Message = $this->getMailerMessage(0);

        $this->assertEmailTextBodyContains($Message, $url, 'URL???'.$url.'?????????????????????');
        $this->assertEmailHtmlBodyNotContains($Message, $url, 'HTML part ??????????????????');

        $this->expected = '['.$this->BaseInfo->getShopName().'] ?????????????????????????????????';
        $this->actual = $Message->getSubject();
        $this->verify();

        $this->expected = $this->Customer->getEmail();
        $this->actual = $Message->getTo()[0]->getAddress();
        $this->verify();

        $this->expected = $this->BaseInfo->getEmail03();
        $this->actual = $Message->getReplyTo()[0]->getAddress();
        $this->verify();
    }

    public function testSendPasswordResetCompleteMail()
    {
        $faker = $this->getFaker();
        $password = $faker->password;
        $this->mailService->sendPasswordResetCompleteMail($this->Customer, $password);
        $this->assertEmailCount(1);
        /** @var Email $Message */
        $Message = $this->getMailerMessage(0);

        $this->expected = '['.$this->BaseInfo->getShopName().'] ????????????????????????????????????';
        $this->actual = $Message->getSubject();
        $this->verify();

        $this->expected = $this->BaseInfo->getEmail03();
        $this->actual = $Message->getReplyTo()[0]->getAddress();
        $this->verify();

        $this->expected = $this->BaseInfo->getEmail01();
        $this->actual = $Message->getBcc()[0]->getAddress();
        $this->verify();

        $this->assertEmailTextBodyContains($Message, '?????????????????????????????????????????????');
        $this->assertEmailHtmlBodyNotContains($Message, '?????????????????????????????????????????????', 'HTML part ??????????????????');
    }

    public function testConvertRFCViolatingEmail()
    {
        $this->expected = new Address('".aa"@example.com');
        $this->actual = $this->mailService->convertRFCViolatingEmail('.aa@example.com');
        $this->verify();

        $this->expected = new Address('"aa."@example.com');
        $this->actual = $this->mailService->convertRFCViolatingEmail('aa.@example.com');
        $this->verify();

        $this->expected = new Address('"a..a"@example.com');
        $this->actual = $this->mailService->convertRFCViolatingEmail('a..a@example.com');
        $this->verify();
    }
}
