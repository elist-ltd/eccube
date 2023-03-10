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

use Eccube\Repository\PluginRepository;
use Eccube\Service\PluginService;
use Eccube\Service\SchemaService;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Tokens;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

class PluginServiceWithEntityExtensionTest extends AbstractServiceTestCase
{
    /**
     * @var PluginService
     */
    private $service;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    private $mockSchemaService;

    /**
     * @var PluginRepository
     */
    private $pluginRepository;

    /**
     * {@inheritdoc}
     *
     * @throws \ReflectionException
     */
    protected function setUp(): void
    {
        // Fixme: because the proxy entity still not working, it's can not help to run this test case
        $this->markTestIncomplete('Fatal error: Cannot declare class Eccube\Entity\BaseInfo, because the name is already in use in app\proxy\entity\BaseInfo.php on line 28');

        parent::setUp();

        $this->mockSchemaService = $this->createMock(SchemaService::class);
        $this->service = static::getContainer()->get(PluginService::class);
        $rc = new \ReflectionClass($this->service);
        $prop = $rc->getProperty('schemaService');
        $prop->setAccessible(true);
        $prop->setValue($this->service, $this->mockSchemaService);

        $this->pluginRepository = $this->entityManager->getRepository(\Eccube\Entity\Plugin::class);
    }

    protected function tearDown(): void
    {
        $finder = new Finder();
        $iterator = $finder
            ->in(static::getContainer()->getParameter('kernel.project_dir').'/app/Plugin')
            ->name('dummy*')
            ->directories();

        $dirs = [];
        foreach ($iterator as $dir) {
            $dirs[] = $dir->getPathName();
        }

        foreach ($dirs as $dir) {
            $this->deleteFile($dir);
        }

        $files = Finder::create()
            ->in(static::getContainer()->getParameter('kernel.project_dir').'/app/proxy/entity')
            ->files();
        $f = new Filesystem();
        $f->remove($files);

        parent::tearDown();
    }

    public function deleteFile($path)
    {
        $f = new Filesystem();

        $f->remove($path);
    }

    /**
     * ?????????????????????????????????????????????????????????????????????????????????
     */
    public function testInstallPlugin()
    {
        list($configA, $fileA) = $this->createDummyPluginWithEntityExtension();

        // ?????????????????????????????????
        $this->mockSchemaService->expects($this->once())->method('updateSchema');

        // ??????????????????
        $this->service->install($fileA);

        // Proxy?????????????????????
        self::assertFalse(file_exists(static::getContainer()->getParameter('kernel.project_dir').'/app/proxy/entity/Customer.php'));
    }

    /**
     * ??????????????????????????????????????????????????????????????????
     */
    public function testEnablePlugin()
    {
        list($configA, $fileA) = $this->createDummyPluginWithEntityExtension();

        // ??????????????????
        $this->service->install($fileA);

        $pluginA = $this->pluginRepository->findOneBy(['code' => $configA['code']]);
        $this->entityManager->detach($pluginA);

        // ?????????
        $this->service->enable($pluginA);

        // Trait?????????
        self::assertContainsTrait(
            static::getContainer()->getParameter('kernel.project_dir').'/app/proxy/entity/Customer.php',
            "Plugin\\${configA['code']}\\Entity\\HogeTrait");
    }

    /**
     * ??????????????????????????????????????????????????????Trait????????????????????????
     */
    public function testDisablePlugin()
    {
        list($configA, $fileA) = $this->createDummyPluginWithEntityExtension();

        // ??????????????????
        $this->service->install($fileA);

        $pluginA = $this->pluginRepository->findOneBy(['code' => $configA['code']]);
        $this->entityManager->detach($pluginA);

        // ?????????
        $this->service->enable($pluginA);

        // ?????????
        $this->service->disable($pluginA);

        // Trait?????????
        self::assertNotContainsTrait(
            static::getContainer()->getParameter('kernel.project_dir').'/app/proxy/entity/Customer.php',
            "Plugin\\${configA['code']}\\Entity\\HogeTrait");
    }

    /**
     * ??????????????????????????????????????????????????????????????????
     */
    public function testUninstallPlugin()
    {
        list($configA, $fileA) = $this->createDummyPluginWithEntityExtension();

        // ??????????????????
        $this->service->install($fileA);

        $pluginA = $this->pluginRepository->findOneBy(['code' => $configA['code']]);
        $this->entityManager->detach($pluginA);

        // ?????????
        $this->service->enable($pluginA);

        // ?????????
        $this->service->disable($pluginA);

        // ?????????????????????????????????
        $this->mockSchemaService->expects($this->once())->method('updateSchema');

        // ??????
        $this->service->uninstall($pluginA);

        // Trait?????????
        self::assertNotContainsTrait(
            static::getContainer()->getParameter('kernel.project_dir').'/app/proxy/entity/Customer.php',
            "Plugin\\${configA['code']}\\Entity\\HogeTrait");
    }

    /**
     * ???????????????????????????????????????????????????????????????????????????????????????????????????????????????
     */
    public function testImmediatelyUninstallPlugin()
    {
        list($configA, $fileA) = $this->createDummyPluginWithEntityExtension();

        // ??????????????????
        $this->service->install($fileA);

        $pluginA = $this->pluginRepository->findOneBy(['code' => $configA['code']]);
        $this->entityManager->detach($pluginA);

        // ?????????
        $this->service->enable($pluginA);

        // ?????????????????????????????????
        $this->mockSchemaService->expects($this->once())->method('updateSchema');

        // ??????
        $this->service->uninstall($pluginA);

        // Trait?????????
        self::assertNotContainsTrait(
            static::getContainer()->getParameter('kernel.project_dir').'/app/proxy/entity/Customer.php',
            "Plugin\\${configA['code']}\\Entity\\HogeTrait");
    }

    /**
     * ????????????????????????(??????)???????????????????????????????????????????????????????????????????????????????????????????????????
     */
    public function testInstallWithEntityExtensionWithDisabledPlugin()
    {
        list($configDisabled, $fileDisabled) = $this->createDummyPluginWithEntityExtension();
        list($configEnabled, $fileEnabled) = $this->createDummyPluginWithEntityExtension();

        // ?????????????????????2?????????????????????
        $this->mockSchemaService->expects($this->exactly(2))->method('updateSchema');

        // ???????????????1???????????????????????????

        $this->service->install($fileDisabled);

        // ???????????????2?????????????????????&?????????

        $this->service->install($fileEnabled);

        $pluginEnabled = $this->pluginRepository->findOneBy(['code' => $configEnabled['code']]);
        $this->entityManager->detach($pluginEnabled);

        // ?????????
        $this->service->enable($pluginEnabled);

        self::assertNotContainsTrait(static::getContainer()->getParameter('kernel.project_dir').'/app/proxy/entity/Customer.php',
            "Plugin\\${configDisabled['code']}\\Entity\\HogeTrait",
            '??????????????????????????????Trait???????????????????????????');

        // ??????????????????Trait???????????????????????????
        self::assertContainsTrait(static::getContainer()->getParameter('kernel.project_dir').'/app/proxy/entity/Customer.php',
            "Plugin\\${configEnabled['code']}\\Entity\\HogeTrait",
            '??????????????????????????????????????????????????????');
    }

    private static function assertContainsTrait($file, $trait, $message = 'Trait?????????????????????????????????')
    {
        $tokens = Tokens::fromCode(file_get_contents($file));
        $useTraitStart = $tokens->getNextTokenOfKind(0, [[CT::T_USE_TRAIT]]);
        $useTraitEnd = $tokens->getNextTokenOfKind($useTraitStart, [';']);
        $useStatement = $tokens->generatePartialCode($useTraitStart, $useTraitEnd);

        self::assertStringContainsString($trait, $useStatement, $message);
    }

    private static function assertNotContainsTrait($file, $trait, $message = 'Trait?????????????????????????????????')
    {
        $tokens = Tokens::fromCode(file_get_contents($file));
        $useTraitStart = $tokens->getNextTokenOfKind(0, [[CT::T_USE_TRAIT]]);
        $useTraitEnd = $tokens->getNextTokenOfKind($useTraitStart, [';']);
        $useStatement = $tokens->generatePartialCode($useTraitStart, $useTraitEnd);

        self::assertNotContains($trait, $useStatement, $message);
    }

    // ??????????????????????????????????????????????????????
    private function createTempDir()
    {
        $t = sys_get_temp_dir().'/plugintest.'.sha1(mt_rand());
        if (!mkdir($t)) {
            throw new \Exception("$t ".$php_errormsg);
        }

        return $t;
    }

    private function createDummyPluginConfig()
    {
        $tmpname = 'dummy'.sha1(mt_rand());
        $config = [];
        $config['name'] = $tmpname.'_name';
        $config['code'] = $tmpname;
        $config['version'] = $tmpname;
        $config['event'] = 'DummyEvent';

        return $config;
    }

    private function createDummyPluginWithEntityExtension()
    {
        // ??????????????????????????????????????????????????????
        $config = $this->createDummyPluginConfig();
        $tmpname = $config['code'];

        $tmpdir = $this->createTempDir();
        $tmpfile = $tmpdir.'/plugin.tar';

        $tar = new \PharData($tmpfile);
        $tar->addFromString('composer.json', json_encode($config));
        $tar->addFromString('Entity/HogeTrait.php', <<< EOT
<?php

namespace Plugin\\${tmpname}\\Entity;

use Eccube\Annotation\EntityExtension;

/**
 * @EntityExtension("Eccube\Entity\Customer")
 */
trait HogeTrait
{
}
EOT
        );

        return [$config, $tmpfile];
    }
}
