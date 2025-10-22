<?php

namespace Icinga\Module\Idbdash\Controllers;

use gipfl\IcingaWeb2\CompatController;
use Icinga\Data\Filter\Filter;
use Icinga\Module\Icingadb\Common\Auth;
use Icinga\Module\Icingadb\Common\Database;
use Icinga\Module\Icingadb\Common\SearchControls;
use Icinga\Module\Icingadb\Redis\VolatileStateResults;
use Icinga\Module\Icingadb\Widget\ItemList\HostList;
use Icinga\Module\Icingadb\Widget\ItemTable\HostItemTable;
use Icinga\Module\Icingadb\Widget\ShowMore;
use Icinga\Module\Idbdash\FilterConverter;
use Icinga\Module\Idbdash\IcingaDbLookup;
use Icinga\Module\Idbdash\IcingaHost;
use Icinga\Module\Idbdash\TimePeriodHelper;
use ipl\Html\Html;
use ipl\Orm\Query;
use ipl\Orm\UnionQuery;
use ipl\Stdlib\Filter\All;
use ipl\Stdlib\Filter\Equal;
use ipl\Stdlib\Filter\Rule;
use ipl\Web\Filter\QueryString;
use ipl\Web\Url;

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
        $url = $this->url();
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
        $sort = $url->shift('sort');
        $newFilter = $this->filterFromQueryString($url->getQueryString(), $stateType);

        $compact = $this->view->compact;
        $this->params->shift('view');
        $this->addSingleTab($this->translate('Hosts'));
        $this->addTitle($this->translate('Hosts'));
        $this->content()->addAttributes(['class' => 'icinga-module module-icingadb']);
        $db = $this->getDb();

        $hosts = IcingaHost::on($db)->with(['state', 'icon_image',  'state.last_comment']);
        if ($limit) {
            $hosts->limit($limit);
        }
        $hosts->getWith()['host.state']->setJoinType('INNER');
        $hosts->setResultSetClass(VolatileStateResults::class);

        // TODO: Do we neet params here? This is redundant
        $this->params->shift('limit');
        $this->params->shift('page');

        $hosts->orderBy($sort ? FilterConverter::convertColumnName($sort) : 'host.state.severity');
        if ($columnString = $this->params->shift('columns', '')) {
            foreach (explode(',', $columnString) as $column) {
                if ($column = trim($column)) {
                    $columns[] = $column;
                }
            }
        } else {
            $columns = ['host.name', 'host.state.output', 'host.vars.os'];
        }
        // TODO: limit, page?

        $hosts->withColumns(array_merge($columns, array_map(FilterConverter::convertColumnName(...), $extraColumns)));
        $this->filter($hosts, $newFilter);
        $hosts->peekAhead($compact);
        // $this->showSql($hosts);

        $results = $hosts->execute();
        $hostList = (new HostList($results));
        $hostList->setViewMode('tabular');
        $hostList = (new HostItemTable($results, HostItemTable::applyColumnMetaData($hosts, $columns)))
            ->setSort('host.status.severity');

        $this->content()->add($hostList);
        // yield $this->export($hosts);

        $this->content()->add(
            (new ShowMore($results, Url::fromRequest()->without(['showCompact', 'limit', 'view'])))
                ->setBaseTarget('_next')
                ->setAttribute('title', sprintf(
                    t('Show all %d hosts'),
                    $hosts->count()
                ))
        );

        $this->setAutorefreshInterval(10);
    }

    protected function filterFromQueryString(string $queryString, $stateType)
    {
        $oldFilter = Filter::fromQueryString($queryString);
        $newFilter = FilterConverter::convert($oldFilter);
        if ($stateType === 'hard') {
            if ($newFilter instanceof All) {
                $newFilter->add(new Equal('host.state.hard_state', '1'));
            } else {
                $newFilter = new All($newFilter, new Equal('host.state.hard_state', '1'));
            }
            // $newFilter->add('host.state.type')
        }

        return $newFilter;
    }

    protected function filter(Query $query, ?Rule $filter = null): self
    {
        if ($this->format !== 'sql' || $this->hasPermission('config/authentication/roles/show')) {
            $this->applyRestrictions($query);
        }

        if ($query instanceof UnionQuery) {
            foreach ($query->getUnions() as $query) {
                $query->filter($filter ?: $this->getFilter());
            }
        } else {
            $query->filter($filter ?: $this->getFilter());
        }

        return $this;
    }

    /**
     * Get the filter created from query string parameters
     *
     * Hint: no longer in use
     */
    protected function getFilter(): Rule
    {
        if ($this->filter === null) {
            $this->filter = QueryString::parse((string) $this->params);

            $db = new IcingaDbLookup();
            $active = TimePeriodHelper::filterActiveTimePeriods($db->loadTimePeriods());
            $filters = array_map(fn ($period) => new Equal(
                'host.vars.sla_id',
                preg_replace('/^sla/', '', $period->name)
            ), $active);
            $this->filter->add(new All(...$filters));
            $this->filter->add(new Equal('host.state.is_problem', 'n'));
        }

        return $this->filter;
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
}
