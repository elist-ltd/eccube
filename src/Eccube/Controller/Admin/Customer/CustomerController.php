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

namespace Eccube\Controller\Admin\Customer;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\QueryBuilder;
use Eccube\Common\Constant;
use Eccube\Controller\AbstractController;
use Eccube\Entity\Master\CsvType;
use Eccube\Event\EccubeEvents;
use Eccube\Event\EventArgs;
use Eccube\Form\Type\Admin\SearchCustomerType;
use Eccube\Repository\CustomerRepository;
use Eccube\Repository\Master\PageMaxRepository;
use Eccube\Repository\Master\PrefRepository;
use Eccube\Repository\Master\SexRepository;
use Eccube\Service\CsvExportService;
use Eccube\Service\MailService;
use Eccube\Util\FormUtil;
use Knp\Component\Pager\PaginatorInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class CustomerController extends AbstractController
{
    /**
     * @var CsvExportService
     */
    protected $csvExportService;

    /**
     * @var MailService
     */
    protected $mailService;

    /**
     * @var PrefRepository
     */
    protected $prefRepository;

    /**
     * @var SexRepository
     */
    protected $sexRepository;

    /**
     * @var PageMaxRepository
     */
    protected $pageMaxRepository;

    /**
     * @var CustomerRepository
     */
    protected $customerRepository;

    public function __construct(
        PageMaxRepository $pageMaxRepository,
        CustomerRepository $customerRepository,
        SexRepository $sexRepository,
        PrefRepository $prefRepository,
        MailService $mailService,
        CsvExportService $csvExportService
    ) {
        $this->pageMaxRepository = $pageMaxRepository;
        $this->customerRepository = $customerRepository;
        $this->sexRepository = $sexRepository;
        $this->prefRepository = $prefRepository;
        $this->mailService = $mailService;
        $this->csvExportService = $csvExportService;
    }

    /**
     * @Route("/%eccube_admin_route%/customer", name="admin_customer", methods={"GET", "POST"})
     * @Route("/%eccube_admin_route%/customer/page/{page_no}", requirements={"page_no" = "\d+"}, name="admin_customer_page", methods={"GET", "POST"})
     * @Template("@admin/Customer/index.twig")
     */
    public function index(Request $request, PaginatorInterface $paginator, $page_no = null)
    {
        $session = $this->session;
        $builder = $this->formFactory->createBuilder(SearchCustomerType::class);

        $event = new EventArgs(
            [
                'builder' => $builder,
            ],
            $request
        );
        $this->eventDispatcher->dispatch($event, EccubeEvents::ADMIN_CUSTOMER_INDEX_INITIALIZE);

        $searchForm = $builder->getForm();

        $pageMaxis = $this->pageMaxRepository->findAll();
        $pageCount = $session->get('eccube.admin.customer.search.page_count', $this->eccubeConfig['eccube_default_page_count']);
        $pageCountParam = $request->get('page_count');
        if ($pageCountParam && is_numeric($pageCountParam)) {
            foreach ($pageMaxis as $pageMax) {
                if ($pageCountParam == $pageMax->getName()) {
                    $pageCount = $pageMax->getName();
                    $session->set('eccube.admin.customer.search.page_count', $pageCount);
                    break;
                }
            }
        }

        if ('POST' === $request->getMethod()) {
            $searchForm->handleRequest($request);
            if ($searchForm->isValid()) {
                $searchData = $searchForm->getData();
                $page_no = 1;

                $session->set('eccube.admin.customer.search', FormUtil::getViewData($searchForm));
                $session->set('eccube.admin.customer.search.page_no', $page_no);
            } else {
                return [
                    'searchForm' => $searchForm->createView(),
                    'pagination' => [],
                    'pageMaxis' => $pageMaxis,
                    'page_no' => $page_no,
                    'page_count' => $pageCount,
                    'has_errors' => true,
                ];
            }
        } else {
            if (null !== $page_no || $request->get('resume')) {
                if ($page_no) {
                    $session->set('eccube.admin.customer.search.page_no', (int) $page_no);
                } else {
                    $page_no = $session->get('eccube.admin.customer.search.page_no', 1);
                }
                $viewData = $session->get('eccube.admin.customer.search', []);
            } else {
                $page_no = 1;
                $viewData = FormUtil::getViewData($searchForm);
                $session->set('eccube.admin.customer.search', $viewData);
                $session->set('eccube.admin.customer.search.page_no', $page_no);
            }
            $searchData = FormUtil::submitAndGetData($searchForm, $viewData);
        }

        /** @var QueryBuilder $qb */
        $qb = $this->customerRepository->getQueryBuilderBySearchData($searchData);

        $event = new EventArgs(
            [
                'form' => $searchForm,
                'qb' => $qb,
            ],
            $request
        );
        $this->eventDispatcher->dispatch($event, EccubeEvents::ADMIN_CUSTOMER_INDEX_SEARCH);

        $pagination = $paginator->paginate(
            $qb,
            $page_no,
            $pageCount
        );

        return [
            'searchForm' => $searchForm->createView(),
            'pagination' => $pagination,
            'pageMaxis' => $pageMaxis,
            'page_no' => $page_no,
            'page_count' => $pageCount,
            'has_errors' => false,
        ];
    }

    /**
     * @Route("/%eccube_admin_route%/customer/{id}/resend", requirements={"id" = "\d+"}, name="admin_customer_resend", methods={"GET"})
     */
    public function resend(Request $request, $id)
    {
        $this->isTokenValid();

        $Customer = $this->customerRepository
            ->find($id);

        if (is_null($Customer)) {
            throw new NotFoundHttpException();
        }
        $secretKey = $this->customerRepository->getUniqueSecretKey();
        $Customer->setSecretKey($secretKey);
        $this->entityManager->persist($Customer);
        $this->entityManager->flush();

        $activateUrl = $this->generateUrl(
            'entry_activate',
            ['secret_key' => $Customer->getSecretKey()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        // ???????????????
        $this->mailService->sendAdminCustomerConfirmMail($Customer, $activateUrl);

        $event = new EventArgs(
            [
                'Customer' => $Customer,
                'activateUrl' => $activateUrl,
            ],
            $request
        );
        $this->eventDispatcher->dispatch($event, EccubeEvents::ADMIN_CUSTOMER_RESEND_COMPLETE);

        $this->addSuccess('admin.common.send_complete', 'admin');

        return $this->redirectToRoute('admin_customer');
    }

    /**
     * @Route("/%eccube_admin_route%/customer/{id}/delete", requirements={"id" = "\d+"}, name="admin_customer_delete", methods={"DELETE"})
     */
    public function delete(Request $request, $id, TranslatorInterface $translator)
    {
        $this->isTokenValid();

        log_info('??????????????????', [$id]);

        $page_no = intval($this->session->get('eccube.admin.customer.search.page_no'));
        $page_no = $page_no ? $page_no : Constant::ENABLED;

        $Customer = $this->customerRepository
            ->find($id);

        if (!$Customer) {
            $this->deleteMessage();

            return $this->redirect($this->generateUrl('admin_customer_page',
                    ['page_no' => $page_no]).'?resume='.Constant::ENABLED);
        }

        try {
            $this->entityManager->remove($Customer);
            $this->entityManager->flush();
            $this->addSuccess('admin.common.delete_complete', 'admin');
        } catch (ForeignKeyConstraintViolationException $e) {
            log_error('??????????????????', [$e]);

            $message = trans('admin.common.delete_error_foreign_key', ['%name%' => $Customer->getName01().' '.$Customer->getName02()]);
            $this->addError($message, 'admin');
        }

        log_info('??????????????????', [$id]);

        $event = new EventArgs(
            [
                'Customer' => $Customer,
            ],
            $request
        );
        $this->eventDispatcher->dispatch($event, EccubeEvents::ADMIN_CUSTOMER_DELETE_COMPLETE);

        return $this->redirect($this->generateUrl('admin_customer_page',
                ['page_no' => $page_no]).'?resume='.Constant::ENABLED);
    }

    /**
     * ??????CSV?????????.
     *
     * @Route("/%eccube_admin_route%/customer/export", name="admin_customer_export", methods={"GET"})
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
            $this->csvExportService->initCsvType(CsvType::CSV_TYPE_CUSTOMER);

            // ??????????????????????????????????????????????????????.
            $qb = $this->csvExportService
                ->getCustomerQueryBuilder($request);

            // ?????????????????????.
            $this->csvExportService->exportHeader();

            // ?????????????????????.
            $this->csvExportService->setExportQueryBuilder($qb);
            $this->csvExportService->exportData(function ($entity, $csvService) use ($request) {
                $Csvs = $csvService->getCsvs();

                /** @var $Customer \Eccube\Entity\Customer */
                $Customer = $entity;

                $ExportCsvRow = new \Eccube\Entity\ExportCsvRow();

                // CSV?????????????????????????????????????????????.
                foreach ($Csvs as $Csv) {
                    // ????????????????????????.
                    $ExportCsvRow->setData($csvService->getData($Csv, $Customer));

                    $event = new EventArgs(
                        [
                            'csvService' => $csvService,
                            'Csv' => $Csv,
                            'Customer' => $Customer,
                            'ExportCsvRow' => $ExportCsvRow,
                        ],
                        $request
                    );
                    $this->eventDispatcher->dispatch($event, EccubeEvents::ADMIN_CUSTOMER_CSV_EXPORT);

                    $ExportCsvRow->pushData();
                }

                // $row[] = number_format(memory_get_usage(true));
                // ??????.
                $csvService->fputcsv($ExportCsvRow->getRow());
            });
        });

        $now = new \DateTime();
        $filename = 'customer_'.$now->format('YmdHis').'.csv';
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('Content-Disposition', 'attachment; filename='.$filename);

        log_info('??????CSV???????????????', [$filename]);

        return $response;
    }
}
