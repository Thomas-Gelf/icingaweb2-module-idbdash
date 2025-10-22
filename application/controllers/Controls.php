<?php

namespace Icinga\Module\Idbdash\Controllers;

use ipl\Orm\Query;
use ipl\Stdlib\Contract\Paginatable;
use ipl\Web\Control\LimitControl;
use ipl\Web\Control\PaginationControl;
use ipl\Web\Control\SortControl;
use ipl\Web\Url;

trait Controls
{
    /**
     * Cache for page size configured via user preferences
     *
     * @var false|int
     */
    protected $userPageSize;

    /**
     * Create and return the SortControl
     *
     * This automatically shifts the sort URL parameter from {@link $params}.
     *
     * @param Query $query
     * @param array $columns Possible sort columns as sort string-label pairs
     * @param ?array|string $defaultSort Optional default sort column
     *
     * @return SortControl
     */
    public function createSortControl(Query $query, array $columns): SortControl
    {
        $sortControl = SortControl::create($columns);

        $this->params->shift($sortControl->getSortParam());

        $sortControl->handleRequest($this->getServerRequest());

        $defaultSort = null;

        if (func_num_args() === 3) {
            $defaultSort = func_get_args()[2];
        }

        return $sortControl->apply($query, $defaultSort);
    }

    /**
     * Create column control
     *
     * @param Query $query
     * @param ViewModeSwitcher $viewModeSwitcher
     *
     * @return array provided columns
     *
     * @throws HttpBadRequestException
     */
    public function createColumnControl(Query $query, ViewModeSwitcher $viewModeSwitcher): array
    {
        // All of that is essentially what `ColumnControl::apply()` should do
        $viewMode = $this->getRequest()->getUrl()->getParam($viewModeSwitcher->getViewModeParam());
        $columnsDef = $this->params->shift('columns');
        if (! $columnsDef) {
            if ($viewMode === 'tabular') {
                $this->httpBadRequest('Missing parameter "columns"');
            }

            return [];
        }

        $columns = [];
        foreach (explode(',', $columnsDef) as $column) {
            if ($column = trim($column)) {
                $columns[] = $column;
            }
        }

        $query->withColumns($columns);

        if (! $viewMode) {
            $viewModeSwitcher->setViewMode('tabular');
        }

        // For now this also returns the columns, but they should be accessible
        // by calling `ColumnControl::getColumns()` in the future
        return $columns;
    }
    /**
     * Check whether the sort control has been submitted and redirect using GET parameters
     */
    protected function handleSortControlSubmit()
    {
        $request = $this->getRequest();
        if (! $request->isPost()) {
            return;
        }

        if (($sort = $request->getPost('sort')) || ($direction = $request->getPost('dir'))) {
            $url = \Icinga\Web\Url::fromRequest();
            if ($sort) {
                $url->setParam('sort', $sort);
                $url->remove('dir');
            } else {
                $url->setParam('dir', $direction);
            }

            $this->redirectNow($url);
        }
    }

    /**
     * Create and return the LimitControl
     *
     * This automatically shifts the limit URL parameter from {@link $params}.
     *
     * @return LimitControl
     */
    protected function createLimitControl(): LimitControl
    {
        $limitControl = new LimitControl(Url::fromRequest());
        $limitControl->setDefaultLimit($this->getPageSize(null));

        $this->params->shift($limitControl->getLimitParam());

        return $limitControl;
    }

    /**
     * Create and return the PaginationControl
     *
     * This automatically shifts the pagination URL parameters from {@link $params}.
     *
     * @param Paginatable $paginatable
     *
     * @return PaginationControl
     */
    protected function createPaginationControl(Paginatable $paginatable): PaginationControl
    {
        $paginationControl = new PaginationControl($paginatable, Url::fromRequest());
        $paginationControl->setDefaultPageSize($this->getPageSize(null));
        $paginationControl->setAttribute('id', $this->getRequest()->protectId('pagination-control'));

        $this->params->shift($paginationControl->getPageParam());
        $this->params->shift($paginationControl->getPageSizeParam());

        return $paginationControl->apply();
    }

    /**
     * Get the page size configured via user preferences or return the default value
     *
     * @param   ?int $default
     *
     * @return  int
     */
    protected function getPageSize($default)
    {
        if ($this->userPageSize === null) {
            $user = $this->Auth()->getUser();
            if ($user !== null) {
                $pageSize = $user->getPreferences()->getValue('icingaweb', 'default_page_size');
                $this->userPageSize = $pageSize ? (int) $pageSize : false;
            } else {
                $this->userPageSize = false;
            }
        }

        return $this->userPageSize !== false ? $this->userPageSize : $default;
    }
}
