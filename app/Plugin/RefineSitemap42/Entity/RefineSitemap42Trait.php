<?php
namespace Plugin\RefineSitemap42\Entity;

use Doctrine\ORM\Mapping as ORM;
use Eccube\Annotation as Eccube;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @Eccube\EntityExtension("Eccube\Entity\Page")
 */
trait RefineSitemap42Trait
{
    /**
     * @ORM\Column(name="sitemap", type="boolean", options={"default":false})
     * @Eccube\FormAppend(
     *     auto_render=true,
     *     type="\Symfony\Component\Form\Extension\Core\Type\CheckboxType",
     *     options={
     *          "required": false,
     *          "label": "サイトマップに表示"
     *     })
     */
    public $sitemap;
}
