<?php

namespace REDCapEntity;

use Exception;
use ExternalModules\ExternalModules;
use Records;
use RedCapDB;
use RCView;
use REDCap;
use REDCapEntity\EntityFactory;
use REDCapEntity\Page;
use REDCapEntity\StatusMessageQueue;
use User;

class EntityList extends Page {

    protected $entityFactory;
    protected $entityTypeKey;
    protected $entityTypeInfo;
    protected $module;
    protected $pageSize;
    protected $pagerSize;
    protected $currPage;
    protected $header = [];
    protected $rows = [];
    protected $rowsAttributes = [];
    protected $totalRows = 0;
    protected $context;
    protected $bulkOperationsEnabled = false;
    protected $formUrl;

    function __construct($entity_type, $module, $page_size = 25, $pager_size = 10) {
        $this->entityFactory = new EntityFactory();

        if (!$info = $this->entityFactory->getEntityTypeInfo($entity_type)) {
            throw new Exception('Invalid entity type.');
        }

        $this->module = $module;
        $this->entityTypeKey = $entity_type;
        $this->entityTypeInfo = $info;

        $this->pageSize = $page_size;
        $this->pagerSize = $pager_size;
        $this->currPage = empty($_GET['pager']) || $_GET['pager'] != intval($_GET['pager']) ? 1 : $_GET['pager'];

        $this->formUrl = ExternalModules::getUrl(REDCAP_ENTITY_PREFIX, 'manager/entity.php');
    }

    function render($context, $title = null, $icon = 'application_view_columns') {
        if (!$title) {
            $title = isset($this->entityTypeInfo['label_plural']) ? $this->entityTypeInfo['label_plural'] : 'Entities';
        }

        $this->context = $context;
        parent::render($context, $title, $icon);
    }

    protected function renderPageBody() {
        $this->processBulkOperations();

        StatusMessageQueue::clear();

        $this->renderAddButton();
        $this->renderExposedFilters();
        $this->renderTable();
        $this->renderPager();
        $this->renderBulkOperations();
    }

    protected function renderAddButton() {
        $operations = $this->getOperations();
        if (!in_array('create', $operations)) {
            return;
        }

        $args = [
            'entity_type' => $this->entityTypeKey,
            'context' => $this->context,
            '__return_url' => REDCap::escapeHtml($_SERVER['REQUEST_URI']),
        ];

        $title = RCView::i(['class' => 'fa fa-plus-circle']) . ' ';
        $title .= isset($this->entityTypeInfo['label']) ? $this->entityTypeInfo['label'] : 'Entity';

        echo RCView::button([
            'class' => 'btn btn-success btn-sm redcap-entity-add-btn',
            'onclick' => 'location.href = "' . $this->formUrl . '&' . http_build_query($args) . '";',
        ], $title);
    }

    protected function getExposedFilters() {
        $filters = [];
        foreach (array_keys($this->getTableHeaderLabels()) as $key) {
            if (isset($this->entityTypeInfo['properties'][$key])) {
                $filters[$key] = $this->entityTypeInfo['properties'][$key];
            }
        }

        return $filters;
    }

    protected function renderExposedFilters() {
        $filters = '';

        foreach (['prefix', 'page', 'pid'] as $key) {
            if (isset($_GET[$key])) {
               $filters .= RCView::hidden(['name' => $key, 'value' => REDCap::escapeHtml($_GET[$key])]);
            }
        }

        foreach ($this->getExposedFilters() as $key => $info) {
            $label = REDCap::escapeHtml($info['name']);
            $value = isset($_GET[$key]) ? $_GET[$key] : '';

            $attrs = [
                'name' => $key,
                'id' => 'redcap-entity-filter-' . $key,
                'class' => 'form-control form-control-sm',
            ];

            $choices = false;

            if ($info['type'] == 'boolean') {
                $choices = ['0' => 'No', '1' => 'Yes'];
            }
            elseif (!empty($info['choices'])) {
                $choices = $info['choices'];
            }
            elseif (!empty($info['choices_callback'])) {
                $entity = $this->entityFactory->getInstance($this->entityTypeKey);
                $choices = $entity->{$info['choices_callback']}();
            }
            elseif ($info['type'] == 'record') {
                $choices = Records::getRecordsAsArray(PROJECT_ID);
                $attrs['class'] .= ' redcap-entity-select';
            }
            elseif ($info['type'] == 'project') {
                $choices = [];
                $attrs['class'] .= ' redcap-entity-select-project';

                if (!empty($value) && ($title = ToDoList::getProjectTitle($data[$key]))) {
                    $choices[$value] = '(' . $value . ') ' . REDCap::escapeHtml($title);
                }
            }
            elseif ($info['type'] == 'user') {
                $choices = [];
                $attrs['class'] .= ' redcap-entity-select-user';

                if (!empty($value) && ($user_info = User::getUserInfo($value))) {
                    $choices[$value] = $value . ' (' . REDCap::escapeHtml($user_info['user_firstname'] . ' ' . $user_info['user_lastname'] . ') - ' . $user_info['user_email']);
                }
            }
            elseif ($info['type'] == 'entity_reference' && !empty($info['entity_type'])) {
                $choices = [];

                $attrs['class'] .= ' redcap-entity-select-entity-reference';
                $attrs['data-entity_type'] = $info['entity_type'];

                if (!empty($value) && ($entity = $this->entityFactory->getInstance($info['entity_type'], $value))) {
                    $choices[$value] = REDCap::escapeHtml($entity->getLabel());
                }
            }

            if ($choices === false) {
                $element = RCView::text($attrs + ['value' => $value, 'placeholder' => $label]);
            }
            else {
                $element = RCView::select($attrs, ['' => '-- ' . $label . ' --'] + $choices, $value);
            }

            $element = RCView::label(['for' => $attrs['id'], 'class' => 'sr-only'], $label) . $element;
            $filters .= RCView::div(['class' => 'form-group'], $element);
        }

        $filters .= RCView::button(['type' => 'submit', 'class' => 'btn btn-sm btn-primary'], 'Submit');
        echo RCView::form(['id' => 'redcap-entity-exp-filters-form', 'class' => 'form-inline'], $filters);
    }

    protected function renderTable() {
        $this->buildTableHeader();
        $this->buildTableRows();

        if (empty($this->rows)) {
            echo RCView::div([], $this->getEmptyResultsMessage());
            return;
        }

        $this->loadTemplate('entity_list', [
            'header' => $this->header,
            'rows' => $this->rows,
            'rows_attributes' => $this->rowsAttributes,
        ]);
    }

    protected function renderPager() {
        if ($this->totalRows <= $this->pageSize) {
            return;
        }

        $this->loadTemplate('pager', [
            'list_max_size' => $this->pageSize,
            'pager_max_size' => $this->pagerSize,
            'total_rows' => $this->totalRows,
        ]);
    }

    protected function renderBulkOperations() {
        if (!$this->totalRows || !($operations = $this->getBulkOperations())) {
            return;
        }

        $btns = '';
        foreach ($operations as $key => $op) {
            $btn_class = 'primary';

            if (!empty($op['color'])) {
                $btn_classes = [
                    'green' => 'success',
                    'yellow' => 'warning',
                    'red' => 'danger',
                    'black' => 'dark',
                    'gray' => 'secondary',
                    'white' => 'light',
                    'light_blue' => 'info',
                ];

                if (isset($btn_classes[$op['color']])) {
                    $btn_class = $btn_classes[$op['color']];
                }
            }

            $btns .= RCView::button([
                'name' => REDCap::escapeHtml($key),
                'data-toggle' => 'modal',
                'data-target' => '#redcap-entity-bulk-operation-modal',
                'class' => 'btn btn-' . $btn_class . ' bulk-operation',
                'disabled' => true,
            ], REDCap::escapeHtml($op['label']));

            $this->loadTemplate('bulk_operation_modal', ['op' => $op, 'btn_class' => $btn_class]);
        }

        echo RCView::div(['class' => 'redcap-entity-bulk-btns'], $btns);
    }

    protected function buildTableRows() {
        $query = $this->getQuery();

        if ($this->pageSize) {
            $count_query = clone $query;
            $this->totalRows = $count_query->countQuery()->execute();

            $query->limit($this->pageSize, ($this->currPage - 1) * $this->pageSize);
        }

        if (!empty($_GET['__order_by']) && in_array($_GET['__order_by'], $this->getSortableColumns())) {
            $query->orderBy($_GET['__order_by'], !empty($_GET['__desc']));
        }
        else {
            $query->orderBy('updated', true);
        }

        if (!$entities = $query->execute()) {
            return;
        }

        foreach ($entities as $id => $entity) {
            $this->rows[$id] = $this->buildTableRow($entity);
        }
    }

    protected function buildTableRow($entity) {
        $data = array_map('REDCap::escapeHtml', $entity->getData());
        $properties = $this->entityTypeInfo['properties'] += [
            'id' => ['name' => '#', 'type' => 'integer'],
            'created' => ['name' => 'Created', 'type' => 'date'],
            'updated' => ['name' => 'Updated', 'type' => 'date'],
        ];

        $row = [];

        foreach (array_keys($this->header) as $key) {
            if ($key == '__bulk_op') {
                $row['__bulk_op'] = RCView::checkbox(['name' => 'entities[]', 'value' => $entity->getId(), 'form' => 'redcap-entity-bulk-form']);
                continue;
            }

            if (in_array($key, ['__update', '__delete'])) {
                $args = [
                    'entity_id' => $entity->getId(),
                    'entity_type' => $this->entityTypeKey,
                    'context' => $this->context,
                    '__return_url' => $_SERVER['REQUEST_URI'],
                ];

                $path = $this->formUrl;

                if ($key == '__update') {
                    $title = 'edit';
                }
                else {
                    $args['__delete'] = true;
                    $title = 'delete';
                }

                $row[$key] = RCView::a(['href' => $path . '&' . http_build_query($args)], $title);
                continue;
            }

            if (in_array($key, ['created', 'updated'])) {
                $row[$key] = date('m/d/Y - h:ia', $data[$key]);
                continue;
            }

            if (!isset($data[$key]) || $data[$key] === '') {
                $row[$key] = '-';
                continue;
            }

            $info = $properties[$key];

            if (!empty($info['choices']) && isset($info['choices'][$data[$key]])) {
                $row[$key] = $info['choices'][$data[$key]];
                continue;
            }

            if (!empty($info['choices_callback']) && method_exists($entity, $info['choices_callback'])) {
                $choices = $entity->{$info['choices_callback']}();

                if (isset($choices[$data[$key]])) {
                    $row[$key] = $choices[$data[$key]];
                    continue;
                }
            }

            $row[$key] = $data[$key];

            switch ($info['type']) {
                case 'boolean':
                    $row[$key] = empty($row[$key]) ? 'No' : 'Yes';
                    break;

                case 'record':
                    if (defined('PROJECT_ID')) {
                        $row[$key] = RCView::a([
                            'href' => APP_PATH_WEBROOT . 'DataEntry/record_home.php?pid=' . PROJECT_ID . '&id=' . $row[$key] . '&arm=' . getArm(),
                            'target' => '_blank',
                        ], $row[$key]);
                    }

                    break;

                case 'date':
                    $row[$key] = date('m/d/Y', $data[$key]);
                    break;

                case 'price':
                    $row[$key] = '$' . number_format($data[$key] / 100, 2);
                    break;

                case 'entity_reference':
                    if (empty($info['entity_type'])) {
                        break;
                    }

                    if (!$target_info = $this->entityFactory->getEntityTypeInfo($info['entity_type'])) {
                        break;
                    }

                    if (!isset($target_info['special_keys']['label'])) {
                        break;
                    }

                    if (!$referenced_entity = $this->entityFactory->getInstance($info['entity_type'], $data[$key])) {
                        break;
                    }

                    // TODO: add link to the entity page, if exists and if
                    // user has access.

                    $row[$key] = REDCap::escapeHtml($referenced_entity->getLabel());
                    break;

                case 'project':
                    $db = new RedCapDB();
                    if (!$project = $db->getProject($data[$key])) {
                        break;
                    }

                    // TODO: check access to add link.
                    $row[$key] = RCView::a(['href' => APP_PATH_WEBROOT . 'ProjectSetup/index.php?pid=' . $row[$key], 'target' => '_blank'], $project->app_title);
                    break;

                case 'user':
                    if (!$user_info = User::getUserInfo($data[$key])) {
                        break;
                    }

                    $url = SUPER_USER || ACCOUNT_MANAGER ? APP_PATH_WEBROOT . 'ControlCenter/view_users.php?username=' . $data[$key] : 'mailto:' . $user_info['user_email'];
                    $row[$key] = '(' . $data[$key] . ') ' . $user_info['user_firstname'] . ' ' . $user_info['user_lastname'];
                    $row[$key] = RCView::a(['href' => $url, 'target' => '_blank'], REDCap::escapeHtml($row[$key]));
            }
        }

        $this->rowsAttributes[$entity->getId()] = $this->getRowAttributes($entity);
        return $row;
    }

    protected function getRowAttributes($entity) {
        return [];
    }

    protected function getTableHeaderLabels() {
        $labels = ['id' => '#'];

        foreach ($this->entityTypeInfo['properties'] as $key => $info) {
            if (!in_array($info['type'], ['json', 'long_text'])) {
                $labels[$key] = $info['name'];
            }
        }

        $labels += [
            'created' => 'Created',
            'updated' => 'Updated',
        ];

        $operations = $this->getOperations();
        foreach (['update', 'delete'] as $op) {
            if (in_array($op, $operations)) {
                $labels['__' . $op] = '';
            }
        }

        if (isset($this->entityTypeInfo['special_keys']['project']) && defined('PROJECT_ID')) {
            unset($labels[$this->entityTypeInfo['special_keys']['project']]);
        }

        return $labels;
    }

    protected function buildTableHeader() {
        $args = [];
        $url = parse_url($_SERVER['REQUEST_URI']);
        $curr_key = '';

        if (!empty($url['query'])) {
            parse_str($url['query'], $args);

            if (isset($args['__order_by'])) {
                $curr_key = $args['__order_by'];
                $direction = empty($args['__desc']) ? 'up' : 'down';
                $icon = RCView::img(['src' => APP_PATH_IMAGES . 'bullet_arrow_' . $direction . '.png']);
            }

            unset($args['__order_by'], $args['__desc']);
        }

        $header = $this->getTableHeaderLabels();
        if ($this->bulkOperationsEnabled) {
            $header = ['__bulk_op' => RCView::checkbox(['name' => 'all_entities', 'form' => 'redcap-entity-bulk-form'])] + $header;
        }

        foreach ($this->getSortableColumns() as $key) {
            if (!isset($header[$key])) {
                continue;
            }

            $args['__order_by'] = $key;

            if ($key == $curr_key) {
                $header[$key] = $icon . ' ' .  $header[$key];

                if ($direction == 'up') {
                    $args['__desc'] = '1';
                }
            }

            $header[$key] = RCView::a(['href' => $url['path'] . '?' . http_build_query($args)], $header[$key]);
        }

        $this->header = $header;
    }

    protected function getSortableColumns() {
        $sortable = ['id', 'created', 'updated'];
        if (isset($this->entityTypeInfo['special_keys']['name'])) {
            $sortable[] = $this->entityTypeInfo['special_keys']['name'];
        }

        return $sortable;
    }

    protected function getQuery() {
        $query = $this->entityFactory->query($this->entityTypeKey);

        foreach ($this->getExposedFilters() as $filter => $info) {
            if (isset($_GET[$filter]) && $_GET[$filter] !== '') {
                if ($info['type'] = 'text' && empty($info['choices']) && empty($info['choices_callback'])) {
                    $query->condition($filter, '%' .$_GET[$filter] . '%', 'like');
                }
                else {
                    $query->condition($filter, $_GET[$filter]);
                }
            }
        }

        if (isset($this->entityTypeInfo['special_keys']['project']) && defined('PROJECT_ID')) {
            $query->condition($this->entityTypeInfo['special_keys']['project'], PROJECT_ID);
        }

        return $query;
    }

    protected function getEmptyResultsMessage() {
        $label = isset($this->entityTypeInfo['label_plural']) ? $this->entityTypeInfo['label_plural'] : 'results';
        return 'There are no ' . $label . '.';
    }

    protected function getOperations() {
        return $this->entityTypeInfo['operations'];
    }

    protected function getBulkOperations() {
        return $this->entityTypeInfo['bulk_operations'];
    }

    protected function processBulkOperations() {
        if (!$operations = $this->getBulkOperations()) {
            return;
        }

        $this->bulkOperationsEnabled = true;

        if ($_SERVER['REQUEST_METHOD'] != 'POST' || empty($_POST['__operation']) || empty($_POST['entities'])) {
            return;
        }

        $op = $_POST['__operation'];

        if (!isset($operations[$op]) || empty($operations[$op]['method'])) {
            return;
        }

        if (!$entities = $this->entityFactory->loadInstances($this->entityTypeKey, $_POST['entities'])) {
            return;
        }

        $this->executeBulkOperation($op, $operations[$op], $entities);
    }

    protected function executeBulkOperation($op, $op_info, $entities) {
        foreach ($entities as $entity) {
            // TODO: check if method exists.
            $entity->{$op_info['method']}();
        }

        // TODO: detect errors.

        if (!empty($op_info['messages']['success'])) {
            StatusMessageQueue::enqueue($op_info['messages']['success']);
        }
    }

    protected function loadPageScripts() {
        $this->jsFiles[] = APP_URL_EXTMOD . 'manager/js/select2.js';
        $this->jsFiles[] = ExternalModules::getUrl(REDCAP_ENTITY_PREFIX, 'manager/js/entity_list.js');
        $this->jsFiles[] = ExternalModules::getUrl(REDCAP_ENTITY_PREFIX, 'manager/js/entity_fields.js');

        parent::loadPageScripts();
    }

    protected function loadPageStyles() {
        $this->cssFiles[] = APP_URL_EXTMOD . 'manager/css/select2.css';
        $this->cssFiles[] = ExternalModules::getUrl(REDCAP_ENTITY_PREFIX, 'manager/css/entity_list.css');

        parent::loadPageStyles();
    }

    protected function loadTemplate($template, $vars = []) {
        extract($vars);
        include dirname(__DIR__) . '/templates/' . $template . '.php';
    }
}
