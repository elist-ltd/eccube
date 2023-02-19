<?php

namespace Plugin\RefineSitemap42;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Application;
use Eccube\Entity\Layout;
use Eccube\Entity\Page;
use Eccube\Entity\Master\DeviceType;
use Eccube\Entity\PageLayout;
use Eccube\Plugin\AbstractPluginManager;
use Eccube\Repository\LayoutRepository;
use Eccube\Repository\PageLayoutRepository;
use Eccube\Repository\PageRepository;
use Eccube\Repository\Master\DeviceTypeRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Plugin\RefineSitemap42\Entity\RefineSitemap42Config;

class PluginManager extends AbstractPluginManager
{

    private $pluginOrgFileDir = __DIR__.'/Resource/template/';

    private $createPages = array(
        [
            'name' => 'サイトマップ',
            'url' => 'sitemap',
            'fileName' => 'Sitemap/sitemap'
        ]
    );

    public function enable(array $meta, ContainerInterface $container)
    {
        $this->copyFiles($container);

        $entityManager = $container->get('doctrine')->getManager();
        $PageLayout = $entityManager->getRepository(Page::class)->findOneBy(['url' => $this->createPages[0]['url'] ]);
        if (is_null($PageLayout)) {
            $this->createPageLayout($container);
        }
    }

    /**
     * @param array $meta
     * @param ContainerInterface $container
     */
    public function disable(array $meta, ContainerInterface $container)
    {
        $entityManager = $container->get('doctrine')->getManager();
        
        $this->removePageLayout($container);
        $this->removeFiles($container);
    }

    /**
     * @param ContainerInterface $container
     */
    private function createPageLayout(ContainerInterface $container)
    {

        $pages = $this->createPages;
        foreach ((array)$pages as $p) {

            /** @var \Eccube\Entity\Page $Page */
            $entityManager = $container->get('doctrine')->getManager();

            $Page = $entityManager->getRepository(Page::class)->newPage();

            $Page->setEditType(Page::EDIT_TYPE_DEFAULT);
            $Page->setName($p['name']);
            $Page->setUrl($p['url']);
            $Page->setFileName($p['fileName']);

            // DB登録
            $entityManager = $container->get('doctrine')->getManager();
            $entityManager->persist($Page);
            $entityManager->flush($Page);

            $Layout = $entityManager->getRepository(Layout::class)->find(Layout::DEFAULT_LAYOUT_UNDERLAYER_PAGE);
            $PageLayout = new PageLayout();
            $PageLayout->setPage($Page)
                ->setPageId($Page->getId())
                ->setLayout($Layout)
                ->setLayoutId($Layout->getId())
                ->setSortNo(0);

            $entityManager->persist($PageLayout);
            $entityManager->flush($PageLayout);
        }
    }

    /**
     * ページレイアウトを削除.
     *
     * @param ContainerInterface $container
     */
    private function removePageLayout(ContainerInterface $container)
    {

        $pages = $this->createPages;
        foreach ($pages as $p) {
            $entityManager = $container->get('doctrine')->getManager();
            $Page = $entityManager->getRepository(Page::class)->findOneBy(['url' => $p['url'] ]);
            if ($Page) {
                $Layout = $entityManager->getRepository(Layout::class)->find(Layout::DEFAULT_LAYOUT_UNDERLAYER_PAGE);
                $PageLayout = $entityManager->getRepository(PageLayout::class)->findOneBy(['Page' => $Page, 'Layout' => $Layout]);
                // Blockの削除
                $entityManager = $container->get('doctrine')->getManager();
                $entityManager->remove($PageLayout);
                $entityManager->remove($Page);
                $entityManager->flush();
            }
        }
    }

    /**
     * Copy template file.
     *
     * @param ContainerInterface $container
     */
    private function copyFiles(ContainerInterface $container)
    {
        $appTemplateDefDir = $container->getParameter('eccube_theme_front_dir');
        $appTemplateAdminDir = $container->getParameter('eccube_theme_admin_dir');

        $file = new Filesystem();

        $file->copy($this->pluginOrgFileDir . 'default/Sitemap/sitemap.twig', $appTemplateDefDir.'/Sitemap/sitemap.twig');
    }

    /**
     * Remove template file.
     *
     * @param ContainerInterface $container
     */
    private function removeFiles(ContainerInterface $container)
    {
        $appTemplateDefDir = $container->getParameter('eccube_theme_front_dir');
        $appTemplateAdminDir = $container->getParameter('eccube_theme_admin_dir');

        $file = new Filesystem();

        $file->remove($appTemplateDefDir.'/Sitemap/sitemap.twig');
    }
}
