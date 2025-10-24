<?php

namespace Icinga\Module\Idbdash\Controllers;

use gipfl\IcingaWeb2\CompatController;
use gipfl\IcingaWeb2\Url;
use Icinga\Data\Filter\Filter;
use Icinga\Module\Icingadb\Common\Auth;
use Icinga\Module\Icingadb\Common\Database;
use Icinga\Module\Icingadb\Common\SearchControls;
use Icinga\Module\Icingadb\Model\Service;
use Icinga\Module\Icingadb\Redis\VolatileStateResults;
use Icinga\Module\Icingadb\Widget\ItemList\HostList;
use Icinga\Module\Icingadb\Widget\ItemList\ServiceList;
use Icinga\Module\Icingadb\Widget\ItemList\StateList;
use Icinga\Module\Icingadb\Widget\ItemTable\HostItemTable;
use Icinga\Module\Icingadb\Widget\ItemTable\ServiceItemTable;
use Icinga\Module\Icingadb\Widget\ItemTable\StateItemTable;
use Icinga\Module\Icingadb\Widget\ShowMore;
use Icinga\Module\Idbdash\FilterConverter;
use Icinga\Module\Idbdash\IcingaHost;
use ipl\Html\Html;
use ipl\Orm\Query;
use ipl\Orm\UnionQuery;
use ipl\Stdlib\Filter\All;
use ipl\Stdlib\Filter\Equal;
use ipl\Stdlib\Filter\Rule;
use ipl\Web\Url as iplUrl;

class DashboardController extends CompatController
{
    use Auth;
    use Controls;
    use Database;
    use SearchControls;

    protected ?string $format = null;

    /** @var ?Rule Filter from query string parameters */
    private ?Rule $filter = null;

    public function init(): void
    {
        $this->handleSortControlSubmit();

        $this->format = $this->params->shift('format'); // TODO: restrict
    }

    public function hostsAction(): void
    {
        $this->prepareHeader($this->translate('Hosts'));
        $query = IcingaHost::on($this->getDb())->with([
            'state',
            'icon_image',
            'state.last_comment'
        ]);
        $query->getWith()['host.state']->setJoinType('INNER');
        $query->setResultSetClass(VolatileStateResults::class);
        $columns = $this->applyUrlToQuery(clone($this->url()), $query);
        $this->showList($query, HostList::class, HostItemTable::class, $columns);
    }

    public function servicesAction(): void
    {
        $this->prepareHeader($this->translate('Services'));
        $query = Service::on($this->getDb())->with([
            'state',
            'state.last_comment',
            'host',
            'host.state',
            'icon_image'
        ]);
        $query->getWith()['service.state']->setJoinType('INNER');
        $query->setResultSetClass(VolatileStateResults::class);
        $columns = $this->applyUrlToQuery(clone($this->url()), $query);
        $this->showList($query, ServiceList::class, ServiceItemTable::class, $columns);
    }

    /**
     * @param Query $query
     * @param class-string<StateList> $listClass
     * @param class-string<StateItemTable> $tableClass
     * @return void
     */
    protected function showList(Query $query, string $listClass, string $tableClass, array $columns): void
    {
        $query->withColumns($columns);
        $query->peekAhead($this->view->compact);

        // $this->showSql($query);
        $results = $query->execute();
        $list = (new $listClass($results));
        // Hint: list also has ->setSort()
        $list->setViewMode('tabular');
        $table = (new $tableClass($results, $tableClass::applyColumnMetaData($query, $columns)));


        $this->content()->add($table);
        // yield $this->export($hosts);

        $this->content()->add(
            (new ShowMore($results, iplUrl::fromRequest()->without(['showCompact', 'limit', 'view'])))
                ->setBaseTarget('_next')
                ->setAttribute('title', sprintf(
                    t('Show all %d rows'),
                    $query->count()
                ))
        );
    }

    protected function applyUrlToQuery(Url $url, Query $query): array
    {

        foreach (['modifyFilter', 'format'] as $ignore) {
            $url->shift($ignore);
        }
        $extraColumns = $url->shift('addColumns');
        if (is_string($extraColumns) && $extraColumns !== '') {
            $extraColumns = explode(',', $extraColumns);
        } else {
            $extraColumns = [];
        }
        $stateType = $url->shift('stateType');
        $limit = $url->shift('limit');

        // TODO: Do we neet params here? This is redundant
        // $this->params->shift('limit');
        // $this->params->shift('page');

        $sort = $url->shift('sort');
        $sortDirection = $url->shift('dir'); // TODO: use it


        $newFilter = $this->filterFromQueryString($url->getQueryString(), $stateType);
        $this->filter($query, $newFilter);

        if ($limit) {
            $query->limit($limit);
        }

        $query->orderBy($sort ? FilterConverter::convertColumnName($sort) : 'host.state.severity', 'DESC');
        if ($columnString = $this->params->shift('columns', '')) {
            // Untested.
            foreach (explode(',', $columnString) as $column) {
                if ($column = trim($column)) {
                    $columns[] = $column;
                }
            }
        } else {
            if ($query instanceof Service) {
                $columns = ['host.name', 'host.state.output', 'host.vars.location'];
            } else {
                $columns = ['service.name', 'host.name', 'host.state.output']; // TODO: params?
            }
        }
        // TODO: limit, page?

        $columns = array_merge($columns, array_map(FilterConverter::convertColumnName(...), $extraColumns));
        $query->withColumns($columns);

        return $columns;
    }

    protected function filterFromQueryString(string $queryString, $stateType): Rule
    {
        $filter = FilterConverter::convert(Filter::fromQueryString($queryString));
        if ($stateType === 'hard') {
            $typeFilter = new Equal('host.state.state_type', 'hard');
            if ($filter instanceof All) {
                $filter->add($typeFilter);
            } else {
                $filter = new All($filter, $typeFilter);
            }
        }

        return $filter;
    }

    protected function filter(Query $query, Rule $filter): self
    {
        if ($this->format !== 'sql' || $this->hasPermission('config/authentication/roles/show')) {
            $this->applyRestrictions($query);
        }

        if ($query instanceof UnionQuery) {
            foreach ($query->getUnions() as $query) {
                $query->filter($filter);
            }
        } else {
            $query->filter($filter);
        }

        return $this;
    }

    protected function showSql(Query $hosts): void
    {
        list($sql, $values) = $hosts->getDb()->getQueryBuilder()->assembleSelect($hosts->assembleSelect());

        $unused = [];
        foreach ($values as $value) {
            $pos = strpos($sql, '?');
            if ($pos !== false) {
                if (is_string($value)) {
                    $value = "'" . $value . "'";
                }

                $sql = substr_replace($sql, $value, $pos, 1);
            } else {
                $unused[] = $value;
            }
        }

        if (!empty($unused)) {
            $sql .= ' /* Unused values: "' . join('", "', $unused) . '" */';
        }

        $this->content()->add(Html::tag('pre', $sql));
    }

    protected function prepareHeader(string $title): void
    {
        $this->params->shift('view');
        if (!$this->view->compact) {
            $this->addSingleTab($title);
            $this->addTitle($title);
        }
        $this->content()->addAttributes(['class' => 'icinga-module module-icingadb']);
        $this->setAutorefreshInterval(10);
    }
}
