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

namespace Eccube\Controller\Admin\Product;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Eccube\Common\Constant;
use Eccube\Controller\AbstractController;
use Eccube\Entity\BaseInfo;
use Eccube\Entity\ExportCsvRow;
use Eccube\Entity\Master\CsvType;
use Eccube\Entity\Master\ProductStatus;
use Eccube\Entity\Product;
use Eccube\Entity\ProductCategory;
use Eccube\Entity\ProductClass;
use Eccube\Entity\ProductImage;
use Eccube\Entity\ProductStock;
use Eccube\Entity\ProductTag;
use Eccube\Event\EccubeEvents;
use Eccube\Event\EventArgs;
use Eccube\Form\Type\Admin\ProductType;
use Eccube\Form\Type\Admin\SearchProductType;
use Eccube\Repository\BaseInfoRepository;
use Eccube\Repository\CategoryRepository;
use Eccube\Repository\Master\PageMaxRepository;
use Eccube\Repository\Master\ProductStatusRepository;
use Eccube\Repository\ProductClassRepository;
use Eccube\Repository\ProductImageRepository;
use Eccube\Repository\ProductRepository;
use Eccube\Repository\TagRepository;
use Eccube\Repository\TaxRuleRepository;
use Eccube\Service\CsvExportService;
use Eccube\Util\CacheUtil;
use Eccube\Util\FormUtil;
use Knp\Component\Pager\PaginatorInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\RouterInterface;

class ProductController extends AbstractController
{
    /**
     * @var CsvExportService
     */
    protected $csvExportService;

    /**
     * @var ProductClassRepository
     */
    protected $productClassRepository;

    /**
     * @var ProductImageRepository
     */
    protected $productImageRepository;

    /**
     * @var TaxRuleRepository
     */
    protected $taxRuleRepository;

    /**
     * @var CategoryRepository
     */
    protected $categoryRepository;

    /**
     * @var ProductRepository
     */
    protected $productRepository;

    /**
     * @var BaseInfo
     */
    protected $BaseInfo;

    /**
     * @var PageMaxRepository
     */
    protected $pageMaxRepository;

    /**
     * @var ProductStatusRepository
     */
    protected $productStatusRepository;

    /**
     * @var TagRepository
     */
    protected $tagRepository;

    /**
     * ProductController constructor.
     *
     * @param CsvExportService $csvExportService
     * @param ProductClassRepository $productClassRepository
     * @param ProductImageRepository $productImageRepository
     * @param TaxRuleRepository $taxRuleRepository
     * @param CategoryRepository $categoryRepository
     * @param ProductRepository $productRepository
     * @param BaseInfoRepository $baseInfoRepository
     * @param PageMaxRepository $pageMaxRepository
     * @param ProductStatusRepository $productStatusRepository
     * @param TagRepository $tagRepository
     */
    public function __construct(
        CsvExportService $csvExportService,
        ProductClassRepository $productClassRepository,
        ProductImageRepository $productImageRepository,
        TaxRuleRepository $taxRuleRepository,
        CategoryRepository $categoryRepository,
        ProductRepository $productRepository,
        BaseInfoRepository $baseInfoRepository,
        PageMaxRepository $pageMaxRepository,
        ProductStatusRepository $productStatusRepository,
        TagRepository $tagRepository
    ) {
        $this->csvExportService = $csvExportService;
        $this->productClassRepository = $productClassRepository;
        $this->productImageRepository = $productImageRepository;
        $this->taxRuleRepository = $taxRuleRepository;
        $this->categoryRepository = $categoryRepository;
        $this->productRepository = $productRepository;
        $this->BaseInfo = $baseInfoRepository->get();
        $this->pageMaxRepository = $pageMaxRepository;
        $this->productStatusRepository = $productStatusRepository;
        $this->tagRepository = $tagRepository;
    }

    /**
     * @Route("/%eccube_admin_route%/product", name="admin_product", methods={"GET", "POST"})
     * @Route("/%eccube_admin_route%/product/page/{page_no}", requirements={"page_no" = "\d+"}, name="admin_product_page", methods={"GET", "POST"})
     * @Template("@admin/Product/index.twig")
     */
    public function index(Request $request, PaginatorInterface $paginator, $page_no = null)
    {
        $builder = $this->formFactory
            ->createBuilder(SearchProductType::class);

        $event = new EventArgs(
            [
                'builder' => $builder,
            ],
            $request
        );
        $this->eventDispatcher->dispatch($event, EccubeEvents::ADMIN_PRODUCT_INDEX_INITIALIZE);

        $searchForm = $builder->getForm();

        /**
         * ???????????????????????????, ??????????????????????????????.
         * - ??????????????????????????????
         * - ???????????????
         * - ??????????????????
         * ??????, ???????????????????????????????????? mtb_page_max????????????, ????????????????????????????????????.
         **/
        $page_count = $this->session->get('eccube.admin.product.search.page_count',
            $this->eccubeConfig->get('eccube_default_page_count'));

        $page_count_param = (int) $request->get('page_count');
        $pageMaxis = $this->pageMaxRepository->findAll();

        if ($page_count_param) {
            foreach ($pageMaxis as $pageMax) {
                if ($page_count_param == $pageMax->getName()) {
                    $page_count = $pageMax->getName();
                    $this->session->set('eccube.admin.product.search.page_count', $page_count);
                    break;
                }
            }
        }

        if ('POST' === $request->getMethod()) {
            $searchForm->handleRequest($request);

            if ($searchForm->isValid()) {
                /**
                 * ?????????????????????????????????, ?????????????????????????????????????????????.
                 * ????????????????????????????????????????????????????????????.
                 */
                $page_no = 1;
                $searchData = $searchForm->getData();

                // ????????????, ??????????????????????????????????????????.
                $this->session->set('eccube.admin.product.search', FormUtil::getViewData($searchForm));
                $this->session->set('eccube.admin.product.search.page_no', $page_no);
            } else {
                // ????????????????????????, ????????????????????????????????????????????????.
                return [
                    'searchForm' => $searchForm->createView(),
                    'pagination' => [],
                    'pageMaxis' => $pageMaxis,
                    'page_no' => $page_no,
                    'page_count' => $page_count,
                    'has_errors' => true,
                ];
            }
        } else {
            if (null !== $page_no || $request->get('resume')) {
                /*
                 * ???????????????????????????????????????????????????????????????????????????, ????????????????????????????????????????????????.
                 */
                if ($page_no) {
                    // ????????????????????????????????????.
                    $this->session->set('eccube.admin.product.search.page_no', (int) $page_no);
                } else {
                    // ?????????????????????????????????.
                    $page_no = $this->session->get('eccube.admin.product.search.page_no', 1);
                }
                $viewData = $this->session->get('eccube.admin.product.search', []);
                $searchData = FormUtil::submitAndGetData($searchForm, $viewData);
            } else {
                /**
                 * ?????????????????????.
                 */
                $page_no = 1;
                // submit default value
                $viewData = FormUtil::getViewData($searchForm);
                $searchData = FormUtil::submitAndGetData($searchForm, $viewData);

                // ?????????????????????????????????, ???????????????????????????.
                $this->session->set('eccube.admin.product.search', $viewData);
                $this->session->set('eccube.admin.product.search.page_no', $page_no);
            }
        }

        $qb = $this->productRepository->getQueryBuilderBySearchDataForAdmin($searchData);

        $event = new EventArgs(
            [
                'qb' => $qb,
                'searchData' => $searchData,
            ],
            $request
        );

        $this->eventDispatcher->dispatch($event, EccubeEvents::ADMIN_PRODUCT_INDEX_SEARCH);

        $sortKey = $searchData['sortkey'];

        if (empty($this->productRepository::COLUMNS[$sortKey]) || $sortKey == 'code' || $sortKey == 'status') {
            $pagination = $paginator->paginate(
                $qb,
                $page_no,
                $page_count
            );
        } else {
            $pagination = $paginator->paginate(
                $qb,
                $page_no,
                $page_count,
                ['wrap-queries' => true]
            );
        }

        return [
            'searchForm' => $searchForm->createView(),
            'pagination' => $pagination,
            'pageMaxis' => $pageMaxis,
            'page_no' => $page_no,
            'page_count' => $page_count,
            'has_errors' => false,
        ];
    }

    /**
     * @Route("/%eccube_admin_route%/product/classes/{id}/load", name="admin_product_classes_load", methods={"GET"}, requirements={"id" = "\d+"}, methods={"GET"})
     * @Template("@admin/Product/product_class_popup.twig")
     * @ParamConverter("Product", options={"repository_method":"findWithSortedClassCategories"})
     */
    public function loadProductClasses(Request $request, Product $Product)
    {
        if (!$request->isXmlHttpRequest() && $this->isTokenValid()) {
            throw new BadRequestHttpException();
        }

        $data = [];
        /** @var $Product ProductRepository */
        if (!$Product) {
            throw new NotFoundHttpException();
        }

        if ($Product->hasProductClass()) {
            $class = $Product->getProductClasses();
            foreach ($class as $item) {
                $data[] = $item;
            }
        }

        return [
            'data' => $data,
        ];
    }

    /**
     * ??????????????????????????????????????????????????????????????????.
     *
     * @see https://pqina.nl/filepond/docs/api/server/#process
     * @Route("/%eccube_admin_route%/product/product/image/process", name="admin_product_image_process", methods={"POST"})
     */
    public function imageProcess(Request $request)
    {
        if (!$request->isXmlHttpRequest() && $this->isTokenValid()) {
            throw new BadRequestHttpException();
        }

        $images = $request->files->get('admin_product');

        $allowExtensions = ['gif', 'jpg', 'jpeg', 'png'];
        $files = [];
        if (count($images) > 0) {
            foreach ($images as $img) {
                foreach ($img as $image) {
                    // ????????????????????????????????????
                    $mimeType = $image->getMimeType();
                    if (0 !== strpos($mimeType, 'image')) {
                        throw new UnsupportedMediaTypeHttpException();
                    }

                    // ?????????
                    $extension = $image->getClientOriginalExtension();
                    if (!in_array(strtolower($extension), $allowExtensions)) {
                        throw new UnsupportedMediaTypeHttpException();
                    }

                    $filename = date('mdHis').uniqid('_').'.'.$extension;
                    $image->move($this->eccubeConfig['eccube_temp_image_dir'], $filename);
                    $files[] = $filename;
                }
            }
        }

        $event = new EventArgs(
            [
                'images' => $images,
                'files' => $files,
            ],
            $request
        );
        $this->eventDispatcher->dispatch($event, EccubeEvents::ADMIN_PRODUCT_ADD_IMAGE_COMPLETE);
        $files = $event->getArgument('files');

        return new Response(array_shift($files));
    }

    /**
     * ???????????????????????????????????????????????????????????????????????????.
     *
     * @see https://pqina.nl/filepond/docs/api/server/#load
     * @Route("/%eccube_admin_route%/product/product/image/load", name="admin_product_image_load", methods={"GET"})
     */
    public function imageLoad(Request $request)
    {
        if (!$request->isXmlHttpRequest()) {
            throw new BadRequestHttpException();
        }

        $dirs = [
            $this->eccubeConfig['eccube_save_image_dir'],
            $this->eccubeConfig['eccube_temp_image_dir'],
        ];

        foreach ($dirs as $dir) {
            if (strpos($request->query->get('source'), '..') !== false) {
                throw new NotFoundHttpException();
            }
            $image = \realpath($dir.'/'.$request->query->get('source'));
            $dir = \realpath($dir);

            if (\is_file($image) && \str_starts_with($image, $dir)) {
                $file = new \SplFileObject($image);

                return $this->file($file, $file->getBasename());
            }
        }

        throw new NotFoundHttpException();
    }

    /**
     * ?????????????????????????????????????????????????????????????????????????????????.
     *
     * @see https://pqina.nl/filepond/docs/api/server/#revert
     * @Route("/%eccube_admin_route%/product/product/image/revert", name="admin_product_image_revert", methods={"DELETE"})
     */
    public function imageRevert(Request $request)
    {
        if (!$request->isXmlHttpRequest() && $this->isTokenValid()) {
            throw new BadRequestHttpException();
        }

        $tempFile = $this->eccubeConfig['eccube_temp_image_dir'].'/'.$request->getContent();
        if (is_file($tempFile) && stripos(realpath($tempFile), $this->eccubeConfig['eccube_temp_image_dir']) === 0) {
            $fs = new Filesystem();
            $fs->remove($tempFile);

            return new Response(null, Response::HTTP_NO_CONTENT);
        }

        throw new NotFoundHttpException();
    }

    /**
     * @Route("/%eccube_admin_route%/product/product/new", name="admin_product_product_new", methods={"GET", "POST"})
     * @Route("/%eccube_admin_route%/product/product/{id}/edit", requirements={"id" = "\d+"}, name="admin_product_product_edit", methods={"GET", "POST"})
     * @Template("@admin/Product/product.twig")
     */
    public function edit(Request $request, RouterInterface $router, CacheUtil $cacheUtil, $id = null)
    {
        $has_class = false;
        if (is_null($id)) {
            $Product = new Product();
            $ProductClass = new ProductClass();
            $ProductStatus = $this->productStatusRepository->find(ProductStatus::DISPLAY_HIDE);
            $Product
                ->addProductClass($ProductClass)
                ->setStatus($ProductStatus);
            $ProductClass
                ->setVisible(true)
                ->setStockUnlimited(true)
                ->setProduct($Product);
            $ProductStock = new ProductStock();
            $ProductClass->setProductStock($ProductStock);
            $ProductStock->setProductClass($ProductClass);
        } else {
            $Product = $this->productRepository->findWithSortedClassCategories($id);
            $ProductClass = null;
            $ProductStock = null;
            if (!$Product) {
                throw new NotFoundHttpException();
            }
            // ????????????????????????????????????????????????????????????????????????????????????
            $has_class = $Product->hasProductClass();
            if (!$has_class) {
                $ProductClasses = $Product->getProductClasses();
                foreach ($ProductClasses as $pc) {
                    if (!is_null($pc->getClassCategory1())) {
                        continue;
                    }
                    if ($pc->isVisible()) {
                        $ProductClass = $pc;
                        break;
                    }
                }
                if ($this->BaseInfo->isOptionProductTaxRule() && $ProductClass->getTaxRule()) {
                    $ProductClass->setTaxRate($ProductClass->getTaxRule()->getTaxRate());
                }
                $ProductStock = $ProductClass->getProductStock();
            }
        }

        $builder = $this->formFactory
            ->createBuilder(ProductType::class, $Product);

        // ???????????????????????????????????????????????????Form????????????
        if ($has_class) {
            $builder->remove('class');
        }

        $event = new EventArgs(
            [
                'builder' => $builder,
                'Product' => $Product,
            ],
            $request
        );
        $this->eventDispatcher->dispatch($event, EccubeEvents::ADMIN_PRODUCT_EDIT_INITIALIZE);

        $form = $builder->getForm();

        if (!$has_class) {
            $ProductClass->setStockUnlimited($ProductClass->isStockUnlimited());
            $form['class']->setData($ProductClass);
        }

        // ?????????????????????
        $images = [];
        $ProductImages = $Product->getProductImage();
        foreach ($ProductImages as $ProductImage) {
            $images[] = $ProductImage->getFileName();
        }
        $form['images']->setData($images);

        $categories = [];
        $ProductCategories = $Product->getProductCategories();
        foreach ($ProductCategories as $ProductCategory) {
            /* @var $ProductCategory \Eccube\Entity\ProductCategory */
            $categories[] = $ProductCategory->getCategory();
        }
        $form['Category']->setData($categories);

        $Tags = $Product->getTags();
        $form['Tag']->setData($Tags);

        if ('POST' === $request->getMethod()) {
            $form->handleRequest($request);
            if ($form->isValid()) {
                log_info('??????????????????', [$id]);
                $Product = $form->getData();

                if (!$has_class) {
                    $ProductClass = $form['class']->getData();

                    // ???????????????
                    if ($this->BaseInfo->isOptionProductTaxRule()) {
                        if ($ProductClass->getTaxRate() !== null) {
                            if ($ProductClass->getTaxRule()) {
                                $ProductClass->getTaxRule()->setTaxRate($ProductClass->getTaxRate());
                            } else {
                                $taxrule = $this->taxRuleRepository->newTaxRule();
                                $taxrule->setTaxRate($ProductClass->getTaxRate());
                                $taxrule->setApplyDate(new \DateTime());
                                $taxrule->setProduct($Product);
                                $taxrule->setProductClass($ProductClass);
                                $ProductClass->setTaxRule($taxrule);
                            }

                            $ProductClass->getTaxRule()->setTaxRate($ProductClass->getTaxRate());
                        } else {
                            if ($ProductClass->getTaxRule()) {
                                $this->taxRuleRepository->delete($ProductClass->getTaxRule());
                                $ProductClass->setTaxRule(null);
                            }
                        }
                    }
                    $this->entityManager->persist($ProductClass);

                    // ?????????????????????
                    if (!$ProductClass->isStockUnlimited()) {
                        $ProductStock->setStock($ProductClass->getStock());
                    } else {
                        // ?????????????????????null?????????
                        $ProductStock->setStock(null);
                    }
                    $this->entityManager->persist($ProductStock);
                }

                // ?????????????????????
                // ???????????????
                /* @var $Product \Eccube\Entity\Product */
                foreach ($Product->getProductCategories() as $ProductCategory) {
                    $Product->removeProductCategory($ProductCategory);
                    $this->entityManager->remove($ProductCategory);
                }
                $this->entityManager->persist($Product);
                $this->entityManager->flush();

                $count = 1;
                $Categories = $form->get('Category')->getData();
                $categoriesIdList = [];
                foreach ($Categories as $Category) {
                    foreach ($Category->getPath() as $ParentCategory) {
                        if (!isset($categoriesIdList[$ParentCategory->getId()])) {
                            $ProductCategory = $this->createProductCategory($Product, $ParentCategory, $count);
                            $this->entityManager->persist($ProductCategory);
                            $count++;
                            /* @var $Product \Eccube\Entity\Product */
                            $Product->addProductCategory($ProductCategory);
                            $categoriesIdList[$ParentCategory->getId()] = true;
                        }
                    }
                    if (!isset($categoriesIdList[$Category->getId()])) {
                        $ProductCategory = $this->createProductCategory($Product, $Category, $count);
                        $this->entityManager->persist($ProductCategory);
                        $count++;
                        /* @var $Product \Eccube\Entity\Product */
                        $Product->addProductCategory($ProductCategory);
                        $categoriesIdList[$Category->getId()] = true;
                    }
                }

                // ???????????????
                $add_images = $form->get('add_images')->getData();
                foreach ($add_images as $add_image) {
                    $ProductImage = new \Eccube\Entity\ProductImage();
                    $ProductImage
                        ->setFileName($add_image)
                        ->setProduct($Product)
                        ->setSortNo(1);
                    $Product->addProductImage($ProductImage);
                    $this->entityManager->persist($ProductImage);

                    // ??????
                    $file = new File($this->eccubeConfig['eccube_temp_image_dir'].'/'.$add_image);
                    $file->move($this->eccubeConfig['eccube_save_image_dir']);
                }

                // ???????????????
                $delete_images = $form->get('delete_images')->getData();
                $fs = new Filesystem();
                foreach ($delete_images as $delete_image) {
                    $ProductImage = $this->productImageRepository->findOneBy([
                        'Product' => $Product,
                        'file_name' => $delete_image,
                    ]);

                    if ($ProductImage instanceof ProductImage) {
                        $Product->removeProductImage($ProductImage);
                        $this->entityManager->remove($ProductImage);
                        $this->entityManager->flush();

                        // ?????????????????????????????????????????????????????????????????????????????????
                        if (!$this->productImageRepository->findOneBy(['file_name' => $delete_image])) {
                            $fs->remove($this->eccubeConfig['eccube_save_image_dir'].'/'.$delete_image);
                        }
                    } else {
                        // ?????????????????????????????????????????????Entity?????????????????????
                        $fs->remove($this->eccubeConfig['eccube_temp_image_dir'].'/'.$delete_image);
                    }
                }

                $this->entityManager->flush();

                if (array_key_exists('product_image', $request->request->get('admin_product'))) {
                    $product_image = $request->request->get('admin_product')['product_image'];
                    foreach ($product_image as $sortNo => $filename) {
                        $ProductImage = $this->productImageRepository
                            ->findOneBy([
                                'file_name' => pathinfo($filename, PATHINFO_BASENAME),
                                'Product' => $Product,
                            ]);
                        if ($ProductImage !== null) {
                            $ProductImage->setSortNo($sortNo);
                            $this->entityManager->persist($ProductImage);
                        }
                    }
                    $this->entityManager->flush();
                }

                // ?????????????????????
                // ??????????????????????????????
                $ProductTags = $Product->getProductTag();
                foreach ($ProductTags as $ProductTag) {
                    $Product->removeProductTag($ProductTag);
                    $this->entityManager->remove($ProductTag);
                }

                // ?????????????????????
                $Tags = $form->get('Tag')->getData();
                foreach ($Tags as $Tag) {
                    $ProductTag = new ProductTag();
                    $ProductTag
                        ->setProduct($Product)
                        ->setTag($Tag);
                    $Product->addProductTag($ProductTag);
                    $this->entityManager->persist($ProductTag);
                }

                $Product->setUpdateDate(new \DateTime());
                $this->entityManager->flush();

                log_info('??????????????????', [$id]);

                $event = new EventArgs(
                    [
                        'form' => $form,
                        'Product' => $Product,
                    ],
                    $request
                );
                $this->eventDispatcher->dispatch($event, EccubeEvents::ADMIN_PRODUCT_EDIT_COMPLETE);

                $this->addSuccess('admin.common.save_complete', 'admin');

                if ($returnLink = $form->get('return_link')->getData()) {
                    try {
                        // $returnLink???path????????????????????????. path???????????????????????????????????????????????????.
                        $pattern = '/^'.preg_quote($request->getBasePath(), '/').'/';
                        $returnLink = preg_replace($pattern, '', $returnLink);
                        $result = $router->match($returnLink);
                        // ???????????????????????????
                        $params = array_filter($result, function ($key) {
                            return 0 !== \strpos($key, '_');
                        }, ARRAY_FILTER_USE_KEY);

                        // path??????url????????????????????????????????????.
                        return $this->redirectToRoute($result['_route'], $params);
                    } catch (\Exception $e) {
                        // ?????????????????????????????????????????????????????????.
                        log_warning('URL???????????????????????????');
                    }
                }

                $cacheUtil->clearDoctrineCache();

                return $this->redirectToRoute('admin_product_product_edit', ['id' => $Product->getId()]);
            }
        }

        // ?????????????????????
        $builder = $this->formFactory
            ->createBuilder(SearchProductType::class);

        $event = new EventArgs(
            [
                'builder' => $builder,
                'Product' => $Product,
            ],
            $request
        );
        $this->eventDispatcher->dispatch($event, EccubeEvents::ADMIN_PRODUCT_EDIT_SEARCH);

        $searchForm = $builder->getForm();

        if ('POST' === $request->getMethod()) {
            $searchForm->handleRequest($request);
        }

        // Get Tags
        $TagsList = $this->tagRepository->getList();

        // ??????????????????????????????????????????????????????????????????
        $TopCategories = $this->categoryRepository->getList(null);
        $ChoicedCategoryIds = array_map(function ($Category) {
            return $Category->getId();
        }, $form->get('Category')->getData());

        return [
            'Product' => $Product,
            'Tags' => $Tags,
            'TagsList' => $TagsList,
            'form' => $form->createView(),
            'searchForm' => $searchForm->createView(),
            'has_class' => $has_class,
            'id' => $id,
            'TopCategories' => $TopCategories,
            'ChoicedCategoryIds' => $ChoicedCategoryIds,
        ];
    }

    /**
     * @Route("/%eccube_admin_route%/product/product/{id}/delete", requirements={"id" = "\d+"}, name="admin_product_product_delete", methods={"DELETE"})
     */
    public function delete(Request $request, CacheUtil $cacheUtil, $id = null)
    {
        $this->isTokenValid();
        $session = $request->getSession();
        $page_no = intval($session->get('eccube.admin.product.search.page_no'));
        $page_no = $page_no ? $page_no : Constant::ENABLED;
        $success = false;

        if (!is_null($id)) {
            /* @var $Product \Eccube\Entity\Product */
            $Product = $this->productRepository->find($id);
            if (!$Product) {
                if ($request->isXmlHttpRequest()) {
                    $message = trans('admin.common.delete_error_already_deleted');

                    return $this->json(['success' => $success, 'message' => $message]);
                } else {
                    $this->deleteMessage();
                    $rUrl = $this->generateUrl('admin_product_page', ['page_no' => $page_no]).'?resume='.Constant::ENABLED;

                    return $this->redirect($rUrl);
                }
            }

            if ($Product instanceof Product) {
                log_info('??????????????????', [$id]);

                $deleteImages = $Product->getProductImage();
                $ProductClasses = $Product->getProductClasses();

                try {
                    $this->productRepository->delete($Product);
                    $this->entityManager->flush();

                    $event = new EventArgs(
                        [
                            'Product' => $Product,
                            'ProductClass' => $ProductClasses,
                            'deleteImages' => $deleteImages,
                        ],
                        $request
                    );
                    $this->eventDispatcher->dispatch($event, EccubeEvents::ADMIN_PRODUCT_DELETE_COMPLETE);
                    $deleteImages = $event->getArgument('deleteImages');

                    // ???????????????????????????(commit?????????????????????)
                    /** @var ProductImage $deleteImage */
                    foreach ($deleteImages as $deleteImage) {
                        if ($this->productImageRepository->findOneBy(['file_name' => $deleteImage->getFileName()])) {
                            continue;
                        }
                        try {
                            $fs = new Filesystem();
                            $fs->remove($this->eccubeConfig['eccube_save_image_dir'].'/'.$deleteImage);
                        } catch (\Exception $e) {
                            // ???????????????????????????????????????
                        }
                    }

                    log_info('??????????????????', [$id]);

                    $success = true;
                    $message = trans('admin.common.delete_complete');

                    $cacheUtil->clearDoctrineCache();
                } catch (ForeignKeyConstraintViolationException $e) {
                    log_info('?????????????????????', [$id]);
                    $message = trans('admin.common.delete_error_foreign_key', ['%name%' => $Product->getName()]);
                }
            } else {
                log_info('?????????????????????', [$id]);
                $message = trans('admin.common.delete_error');
            }
        } else {
            log_info('?????????????????????', [$id]);
            $message = trans('admin.common.delete_error');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => $success, 'message' => $message]);
        } else {
            if ($success) {
                $this->addSuccess($message, 'admin');
            } else {
                $this->addError($message, 'admin');
            }

            $rUrl = $this->generateUrl('admin_product_page', ['page_no' => $page_no]).'?resume='.Constant::ENABLED;

            return $this->redirect($rUrl);
        }
    }

    /**
     * @Route("/%eccube_admin_route%/product/product/{id}/copy", requirements={"id" = "\d+"}, name="admin_product_product_copy", methods={"POST"})
     */
    public function copy(Request $request, $id = null)
    {
        $this->isTokenValid();

        if (!is_null($id)) {
            $Product = $this->productRepository->find($id);
            if ($Product instanceof Product) {
                $CopyProduct = clone $Product;
                $CopyProduct->copy();
                $ProductStatus = $this->productStatusRepository->find(ProductStatus::DISPLAY_HIDE);
                $CopyProduct->setStatus($ProductStatus);

                $CopyProductCategories = $CopyProduct->getProductCategories();
                foreach ($CopyProductCategories as $Category) {
                    $this->entityManager->persist($Category);
                }

                // ??????????????????????????????, ??????????????????????????????????????????????????????.
                if ($CopyProduct->hasProductClass()) {
                    $dummyClass = $this->productClassRepository->findOneBy([
                        'visible' => false,
                        'ClassCategory1' => null,
                        'ClassCategory2' => null,
                        'Product' => $Product,
                    ]);
                    $dummyClass = clone $dummyClass;
                    $dummyClass->setProduct($CopyProduct);
                    $CopyProduct->addProductClass($dummyClass);
                }

                $CopyProductClasses = $CopyProduct->getProductClasses();
                foreach ($CopyProductClasses as $Class) {
                    $Stock = $Class->getProductStock();
                    $CopyStock = clone $Stock;
                    $CopyStock->setProductClass($Class);
                    $this->entityManager->persist($CopyStock);

                    $TaxRule = $Class->getTaxRule();
                    if ($TaxRule) {
                        $CopyTaxRule = clone $TaxRule;
                        $CopyTaxRule->setProductClass($Class);
                        $CopyTaxRule->setProduct($CopyProduct);
                        $this->entityManager->persist($CopyTaxRule);
                    }
                    $this->entityManager->persist($Class);
                }
                $Images = $CopyProduct->getProductImage();
                foreach ($Images as $Image) {
                    // ?????????????????????????????????
                    $extension = pathinfo($Image->getFileName(), PATHINFO_EXTENSION);
                    $filename = date('mdHis').uniqid('_').'.'.$extension;
                    try {
                        $fs = new Filesystem();
                        $fs->copy($this->eccubeConfig['eccube_save_image_dir'].'/'.$Image->getFileName(), $this->eccubeConfig['eccube_save_image_dir'].'/'.$filename);
                    } catch (\Exception $e) {
                        // ???????????????????????????????????????
                    }
                    $Image->setFileName($filename);

                    $this->entityManager->persist($Image);
                }
                $Tags = $CopyProduct->getProductTag();
                foreach ($Tags as $Tag) {
                    $this->entityManager->persist($Tag);
                }

                $this->entityManager->persist($CopyProduct);

                $this->entityManager->flush();

                $event = new EventArgs(
                    [
                        'Product' => $Product,
                        'CopyProduct' => $CopyProduct,
                        'CopyProductCategories' => $CopyProductCategories,
                        'CopyProductClasses' => $CopyProductClasses,
                        'images' => $Images,
                        'Tags' => $Tags,
                    ],
                    $request
                );
                $this->eventDispatcher->dispatch($event, EccubeEvents::ADMIN_PRODUCT_COPY_COMPLETE);

                $this->addSuccess('admin.product.copy_complete', 'admin');

                return $this->redirectToRoute('admin_product_product_edit', ['id' => $CopyProduct->getId()]);
            } else {
                $this->addError('admin.product.copy_error', 'admin');
            }
        } else {
            $msg = trans('admin.product.copy_error');
            $this->addError($msg, 'admin');
        }

        return $this->redirectToRoute('admin_product');
    }

    /**
     * ??????CSV?????????.
     *
     * @Route("/%eccube_admin_route%/product/export", name="admin_product_export", methods={"GET"})
     *
     * @param Request $request
     *
     * @return StreamedResponse
     */
    public function export(Request $request)
    {
        // ????????????????????????????????????.
        set_time_limit(0);

        // sql logger??????????????????.
        $em = $this->entityManager;
        $em->getConfiguration()->setSQLLogger(null);

        $response = new StreamedResponse();
        $response->setCallback(function () use ($request) {
            // CSV????????????????????????.
            $this->csvExportService->initCsvType(CsvType::CSV_TYPE_PRODUCT);

            // ??????????????????????????????????????????????????????.
            $qb = $this->csvExportService
                ->getProductQueryBuilder($request);

            // ?????????????????????.
            $this->csvExportService->exportHeader();

            // Get stock status
            $isOutOfStock = 0;
            $session = $request->getSession();
            if ($session->has('eccube.admin.product.search')) {
                $searchData = $session->get('eccube.admin.product.search', []);
                if (isset($searchData['stock_status']) && $searchData['stock_status'] === 0) {
                    $isOutOfStock = 1;
                }
            }

            // join???????????????iterate?????????????????????, select??????distinct??????.
            // http://qiita.com/suin/items/2b1e98105fa3ef89beb7
            // distinct???mysql???pgsql????????????????????????.
            // http://uedatakeshi.blogspot.jp/2010/04/distinct-oeder-by-postgresmysql.html
            $qb->resetDQLPart('select');

            if ($isOutOfStock) {
                $qb->select('p, pc')
                    ->distinct();
            } else {
                $qb->select('p')
                    ->distinct();
            }
            // ?????????????????????.
            $this->csvExportService->setExportQueryBuilder($qb);

            $this->csvExportService->exportData(function ($entity, CsvExportService $csvService) use ($request) {
                $Csvs = $csvService->getCsvs();

                /** @var $Product \Eccube\Entity\Product */
                $Product = $entity;

                /** @var $ProductClasses \Eccube\Entity\ProductClass[] */
                $ProductClasses = $Product->getProductClasses();

                foreach ($ProductClasses as $ProductClass) {
                    $ExportCsvRow = new ExportCsvRow();

                    // CSV?????????????????????????????????????????????.
                    foreach ($Csvs as $Csv) {
                        // ????????????????????????.
                        $ExportCsvRow->setData($csvService->getData($Csv, $Product));
                        if ($ExportCsvRow->isDataNull()) {
                            // ???????????????????????????.
                            $ExportCsvRow->setData($csvService->getData($Csv, $ProductClass));
                        }

                        $event = new EventArgs(
                            [
                                'csvService' => $csvService,
                                'Csv' => $Csv,
                                'ProductClass' => $ProductClass,
                                'ExportCsvRow' => $ExportCsvRow,
                            ],
                            $request
                        );
                        $this->eventDispatcher->dispatch($event, EccubeEvents::ADMIN_PRODUCT_CSV_EXPORT);

                        $ExportCsvRow->pushData();
                    }

                    // $row[] = number_format(memory_get_usage(true));
                    // ??????.
                    $csvService->fputcsv($ExportCsvRow->getRow());
                }
            });
        });

        $now = new \DateTime();
        $filename = 'product_'.$now->format('YmdHis').'.csv';
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('Content-Disposition', 'attachment; filename='.$filename);

        log_info('??????CSV?????????????????????', [$filename]);

        return $response;
    }

    /**
     * ProductCategory??????
     *
     * @param \Eccube\Entity\Product $Product
     * @param \Eccube\Entity\Category $Category
     * @param integer $count
     *
     * @return \Eccube\Entity\ProductCategory
     */
    private function createProductCategory($Product, $Category, $count)
    {
        $ProductCategory = new ProductCategory();
        $ProductCategory->setProduct($Product);
        $ProductCategory->setProductId($Product->getId());
        $ProductCategory->setCategory($Category);
        $ProductCategory->setCategoryId($Category->getId());

        return $ProductCategory;
    }

    /**
     * Bulk public action
     *
     * @Route("/%eccube_admin_route%/product/bulk/product-status/{id}", requirements={"id" = "\d+"}, name="admin_product_bulk_product_status", methods={"POST"})
     *
     * @param Request $request
     * @param ProductStatus $ProductStatus
     *
     * @return RedirectResponse
     */
    public function bulkProductStatus(Request $request, ProductStatus $ProductStatus, CacheUtil $cacheUtil)
    {
        $this->isTokenValid();

        /** @var Product[] $Products */
        $Products = $this->productRepository->findBy(['id' => $request->get('ids')]);
        $count = 0;
        foreach ($Products as $Product) {
            try {
                $Product->setStatus($ProductStatus);
                $this->productRepository->save($Product);
                $count++;
            } catch (\Exception $e) {
                $this->addError($e->getMessage(), 'admin');
            }
        }
        try {
            if ($count) {
                $this->entityManager->flush();
                $msg = $this->translator->trans('admin.product.bulk_change_status_complete', [
                    '%count%' => $count,
                    '%status%' => $ProductStatus->getName(),
                ]);
                $this->addSuccess($msg, 'admin');
                $cacheUtil->clearDoctrineCache();
            }
        } catch (\Exception $e) {
            $this->addError($e->getMessage(), 'admin');
        }

        return $this->redirectToRoute('admin_product', ['resume' => Constant::ENABLED]);
    }
}
