<?php

namespace Exam\Qhodir\Model\Resolver;

use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\CatalogGraphQl\Model\Resolver\Products\Query\ProductQueryInterface;
use Magento\CatalogGraphQl\DataProvider\Product\SearchCriteriaBuilder;

class GetProduct implements ResolverInterface
{

    const ENDPOINT = "";

    /**
     * @var \Magento\Framework\Json\Helper\Data
     */
    private $jsonHelper;
    /**
     * @var \Magento\Framework\HTTP\Client\Curl
     */
    private $curl;

    /**
     * @var ProductQueryInterface
     */
    private $searchQuery;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchApiCriteriaBuilder;

    public function __construct(
        \Magento\Framework\Json\Helper\Data $jsonHelper,
        \Magento\Framework\HTTP\Client\Curl $curl,
        ProductQueryInterface $searchQuery,
        SearchCriteriaBuilder $searchApiCriteriaBuilder = null
    ) {
        $this->jsonHelper = $jsonHelper;
        $this->curl = $curl;
        $this->searchQuery = $searchQuery;
        $this->searchApiCriteriaBuilder = $searchApiCriteriaBuilder ??
            \Magento\Framework\App\ObjectManager::getInstance()->get(SearchCriteriaBuilder::class);
    }

    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null) {

        $this->validateInput($args);

        $searchResult = $this->searchQuery->getResult($args, $info, $context);

        if ($searchResult->getCurrentPage() > $searchResult->getTotalPages() && $searchResult->getTotalCount() > 0) {
            throw new GraphQlInputException(
                __(
                    'currentPage value %1 specified is greater than the %2 page(s) available.',
                    [$searchResult->getCurrentPage(), $searchResult->getTotalPages()]
                )
            );
        }

        $data = [
            'total_count' => $searchResult->getTotalCount(),
            'items' => $searchResult->getProductsSearchResult(),
            'suggestions' => $searchResult->getSuggestions(),
            'page_info' => [
                'page_size' => $searchResult->getPageSize(),
                'current_page' => $searchResult->getCurrentPage(),
                'total_pages' => $searchResult->getTotalPages()
            ],
            'search_result' => $searchResult,
        ];

        if (isset($args['filter']['category_id'])) {
            $data['categories'] = $args['filter']['category_id']['eq'] ?? $args['filter']['category_id']['in'];
            $data['categories'] = is_array($data['categories']) ? $data['categories'] : [$data['categories']];
        }

        return $data;
    }

    /**
     * Validate input arguments
     *
     * @param array $args
     * @throws GraphQlAuthorizationException
     * @throws GraphQlInputException
     */
    private function validateInput(array $args)
    {
        if (isset($args['searchAllowed']) && $args['searchAllowed'] === false) {
            throw new GraphQlAuthorizationException(__('Product search has been disabled.'));
        }
        if ($args['currentPage'] < 1) {
            throw new GraphQlInputException(__('currentPage value must be greater than 0.'));
        }
        if ($args['pageSize'] < 1) {
            throw new GraphQlInputException(__('pageSize value must be greater than 0.'));
        }
        if (!isset($args['search']) && !isset($args['filter'])) {
            throw new GraphQlInputException(
                __("'search' or 'filter' input argument is required.")
            );
        }
    }

    private function postQuery(string $query)
    {
        $data = $this->jsonHelper->jsonEncode(['query' => $query, 'variables' => []]);
        $this->curl->addHeader("Content-Type", "application/json");
        $this->curl->addHeader("Content-Length", strlen($data));
        // $this->curl->addHeader('Authorization', 'Bearer ' . self::$token);
        $this->curl->post(self::$endPoint, $data);

        $result = $this->curl->getBody();

        if (!empty($result)) {
            $result = $this->jsonHelper->jsonDecode($result);
            if (isset($result['errors'])) {
                // error wrapping
            }
        }
    }
    
}
