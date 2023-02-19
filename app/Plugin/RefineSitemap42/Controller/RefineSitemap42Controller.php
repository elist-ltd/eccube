<?php

declare(strict_types=1);
namespace Plugin\RefineSitemap42\Controller;

use Eccube\Controller\AbstractController;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Class RefineSitemap42Controller.
 */
class RefineSitemap42Controller extends AbstractController
{
    /**
     * サイトマップ
     * @Route("/sitemap", name="sitemap")
     * @Template("Sitemap/sitemap.twig")
     */
    public function sitemap(Request $request, RouterInterface $router)
    {
        return [
            'request' => $request,
            'router' => $router
        ];
    }
}
