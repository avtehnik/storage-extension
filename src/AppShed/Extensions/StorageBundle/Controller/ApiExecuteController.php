<?php
/**
 * Created by Igor Dubiy on 16/06/2014
 */

namespace AppShed\Extensions\StorageBundle\Controller;

use AppShed\Extensions\StorageBundle\Entity\Api;
use AppShed\Extensions\StorageBundle\Entity\Data;
use AppShed\Extensions\StorageBundle\Entity\Field;
use AppShed\Extensions\StorageBundle\Entity\Filter;
use AppShed\Extensions\StorageBundle\Exception\MissingDataException;
use AppShed\Extensions\StorageBundle\Exception\NotImplementedException;
use AppShed\Extensions\StorageBundle\Form\ApiEditType;
use AppShed\Extensions\StorageBundle\Form\ApiType;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use Symfony\Component\Routing\Exception\MethodNotAllowedException;

class ApiExecuteController extends Controller
{
    /**
     * @Route("/api/{uuid}", defaults={"_format": "json"}, name="api_show")
     * @ParamConverter("api", class="AppShed\Extensions\StorageBundle\Entity\Api", options={"uuid"="uuid"})
     * @Method({"GET", "POST"})
     */
    public function showAction(Request $request, Api $api)
    {
        if (in_array($api->getAction(), [Api::ACTION_INSERT, Api::ACTION_UPDATE, Api::ACTION_DELETE]) && $request->getMethod() != 'POST') {
            throw new MethodNotAllowedException(['POST']);
        }

        $result = [];
        switch ($api->getAction()) {
            case Api::ACTION_SELECT: {
                $result = $this->selectData($api, $request);
            } break;
            case Api::ACTION_INSERT: {
                $result = $this->insertData($api, $request);
            } break;
            case Api::ACTION_UPDATE: {
                $result = $this->updateData($api, $request);
            } break;
            case Api::ACTION_DELETE: {
                $result = $this->deleteData($api, $request);
            } break;
        }
        return new JsonResponse($result, 200);
    }




    private function deleteData(Api $api, Request $request) {
        $result = [];

        $filters = $request->request->get('filters', '');
        $additionalFilters = $this->getAdditionalFilters($filters);
        //GET FILTERED DATA
        $storeData = $this->getDoctrine()->getManager()->getRepository('AppShedExtensionsStorageBundle:Data')->getDataForApi($api, $additionalFilters);

        if ($api->getLimit()) {
            $storeData = $this->limitResults($storeData, $api->getLimit());
        }

        $executed = 0;
        if (count($storeData)) {
            $em = $this->getDoctrine()->getManager();
            foreach ($storeData as $k => $value) {
                $em->remove($storeData[$k]);
            }
            $executed++;
            $em->flush();
        }
        return ['updatedRows' => $executed];
    }


    private function updateData(Api $api, Request $request) {
        $result = [];
        //get this data here (before getDataForApi()) because $em->clear() make bad
        $storeColumns = $api->getStore()->getColumns();
        $appId = $api->getApp()->getAppId();

        $filters = $request->request->get('filters', '');
        $request->request->remove('filters');
        $additionalFilters = $this->getAdditionalFilters($filters);
        //GET FILTERED DATA
        $storeData = $this->getDoctrine()->getManager()->getRepository('AppShedExtensionsStorageBundle:Data')->getDataForApi($api, $additionalFilters);

        if ($api->getLimit()) {
            $storeData = $this->limitResults($storeData, $api->getLimit());
        }
        $updateData = $request->request->all();

        $executed = 0;

        if (empty($updateData)) {
            throw new MissingDataException('No data given');
        }

        foreach ($storeData as $k => $value) {
            $data = $storeData[$k]->getData();
            $newData = array_merge($data, $updateData);
            $storeData[$k]->setData($newData);
            $storeData[$k]->setColumns(array_keys($newData));
            $executed++;
        }

        $store = $api->getStore();
        $em = $this->getDoctrine()->getManager();
        //Add any new columns to the store
        $newColumns = array_diff(array_keys($updateData), $storeColumns);
        if (count($newColumns)) {
            $store->setColumns(array_merge($storeColumns, $newColumns));
        }
        $em->merge($store);
        $em->flush();

        return ['updatedRows' => $executed];
    }

    private function insertData(Api $api, Request $request) {
        //get this data here (before getDataForApi()) because $em->clear() make bad
        $storeColumns = $api->getStore()->getColumns();
        $appId = $api->getApp()->getAppId();

        $data = $request->request->all();
        $executed = 0;

        if (empty($data)) {
            throw new MissingDataException('No data given');
        }

        $dataO = new Data();
        $dataO->setStore($api->getStore());
        $dataO->setColumns(array_keys($data));
        $dataO->setData($data);
        $this->getDoctrine()->getManager()->persist($dataO);
        $this->getDoctrine()->getManager()->flush();

        $store = $api->getStore();
        $em = $this->getDoctrine()->getManager();
        //Add any new columns to the store
        $newColumns = array_diff(array_keys($data), $storeColumns);
        if (count($newColumns)) {
            $store->setColumns(array_merge($storeColumns, $newColumns));
        }
        $em->merge($store);
        $em->flush();
        $executed++;

        return ['updatedRows' => $executed];
    }

    private function selectData(Api $api, Request $request) {
        $result = [];

        $filters = $request->request->get('filters', '');
        $additionalFilters = $this->getAdditionalFilters($filters);
        //GET FILTERED DATA
        $storeData = $this->getDoctrine()->getManager()->getRepository('AppShedExtensionsStorageBundle:Data')->getDataForApi($api, $additionalFilters);

        $sql['select'] = [];
        foreach ($api->getFields() as $field) {
            $sql['select'][] = $field->getField();
            if ($field->getAggregate()) {
                $sql['aggregate'] = $field;
            }
        }

        //SET DATA FIELDS BY SELECT STATEMENT
        /** @var Data $row */
        foreach ($storeData as $row) {
            $data = $row->getData();
            $record = [];
            foreach ($sql['select'] as $field) {
                $record[$field] = ((isset($data[$field])) ? ($data[$field]) : (null));
            }
            $result[] = $record;
        }

        //GROUP BY && DO AGGREGATE FUNCTIONS
        if ($api->getGroupField() || isset($sql['aggregate'])) {
            //GROUP BY
            if ($api->getGroupField()) {
                $resultGroup = [];
                foreach ($result as $record) {
                    $groupValue = $record[$api->getGroupField()];
                    if (isset($resultGroup[$groupValue])) {
                        $resultGroup[$groupValue][] = $record;
                    } else {
                        $resultGroup[$groupValue] = [$record];
                    }
                }
            } else {
                //just same format, for aggregation
                $resultGroup = [$result];
            }

            // DO AGGREGATE FUNCTIONS (if any)
            /** @var Field $sql['aggregate']  */
            if (isset($sql['aggregate'])) {
                $resultField = $sql['aggregate']->getField();
                foreach ($resultGroup as $key => $resultGroupRecord) {
                    $functionInputData = [];
                    foreach ($resultGroupRecord as $record) {
                        if ($record[$sql['aggregate']->getArg()] != null) {
                            $functionInputData[] = $record[$sql['aggregate']->getArg()];
                        }
                    }
                    $resultGroup[$key][0][$resultField] = $this->aggregateFunction($sql['aggregate']->getAggregate(), $functionInputData);
                }
            }

            $result = [];
            foreach ($resultGroup as $key => $resultGroupRecord) {
                $result[] = $resultGroupRecord[0];
            }
        }

        //ORDER RESULTS
        if ($api->getOrderField()) {
            if ($api->getOrderDirection() == Api::ODRER_DIRECTION_ASC) {
                //Make different functions to decrease count IF statements inside function
                usort($result, $this->sortOrderAsc($api->getOrderField()));
            } else {
                usort($result, $this->sortOrderDesc($api->getOrderField()));
            }
        }

        //LIMIT RESULTS
        if ($api->getLimit()) {
            $result = $this->limitResults($result, $api->getLimit());
        }
        return $result;
    }

    private function getAdditionalFilters($filters = '') {
        $additionalFilters = [];
        if ($filters) {
            $allowedOperations = [Filter::FILTER_GREATER_THAN_OR_EQUALS, Filter::FILTER_GREATER_THAN, Filter::FILTER_LESS_THAN_OR_EQUALS, Filter::FILTER_LESS_THAN, Filter::FILTER_NOT_EQUALS, Filter::FILTER_EQUALS]; //order of elements is important
            $statements = explode(' and ', strtolower($filters));
            foreach ($statements as $statement) {
                foreach ($allowedOperations as $operation) {
                    if (strpos($statement, $operation) !== FALSE) {
                        $statementParts = explode($operation, $statement);
                        $filter = new Filter();
                        $filter->setCol(trim($statementParts[0]));
                        $filter->setType($operation);
                        $filter->setValue(trim($statementParts[1]));
                        $additionalFilters[] = $filter;
                    }
                }
            }
        }
        return $additionalFilters;
    }

    private function limitResults($result, $limit = '') {
        $limitParts = explode(',', $limit);
        if (count($limitParts) == 2) {
            $offset = trim($limitParts[0]);
            $count = trim($limitParts[1]);
        } else {
            $offset = 0;
            $count = trim($limitParts[0]);
        }
        return array_slice($result, $offset, $count);
    }

    private function aggregateFunction($function, $input) {
        switch ($function) {
            case 'count':
                return count($input);
            case 'sum':
                return array_sum($input);
            case 'avg':
                return array_sum($input) / count($input);
            case 'max':
                return max($input);
            case 'min':
                return min($input);
            default:
                throw new NotImplementedException("Aggregate function '$function' not  implemented");

        }
    }

    private function sortOrderAsc($field) {
        return function ($a, $b) use ($field) {
            return $a[$field] > $b[$field];
        };
    }

    private function sortOrderDesc($field) {
        return function ($a, $b) use ($field) {
            return $a[$field] < $b[$field];
        };
    }
}
