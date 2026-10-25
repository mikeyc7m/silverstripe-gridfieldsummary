<?php

namespace Mikeyc7m\GridFieldSummary;

use DateTime;
use SilverStripe\Forms\GridField\GridField_DataManipulator;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\FieldType\DBBoolean;
use SilverStripe\ORM\FieldType\DBCurrency;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBDecimal;
use SilverStripe\ORM\FieldType\DBFloat;
use SilverStripe\ORM\FieldType\DBInt;
use SilverStripe\ORM\ManyManyList;
use SilverStripe\View\ArrayData;
use SilverStripe\View\SSViewer;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\Core\ClassInfo;

class GridFieldSummaryFooter implements GridField_HTMLProvider
{
    private const NUMERIC_TYPES = [
        'Int', 'Float', 'Decimal', 'Currency',
        DBInt::class, DBFloat::class, DBDecimal::class, DBCurrency::class,
    ];

    private const DATE_TYPES = [
        'DateTime', 'Date', DBDate::class, DBDatetime::class,
    ];

    private const BOOLEAN_TYPES = [
        'Boolean', DBBoolean::class,
    ];

    private $fragment;

    public function __construct($fragment = 'footer')
    {
        $this->fragment = $fragment;
    }

    protected function getDateRangeLabel($span, $format)
    {
        $ref = null;

        switch ($format) {
            case 'sec':
                $ref = '.SECONDS_SHORT_PLURALS';
                break;
            case 'min':
                $ref = '.MINUTES_SHORT_PLURALS';
                break;
            case 'hour':
                $ref = '.HOURS_SHORT_PLURALS';
                break;
            case 'day':
                $ref = '.DAYS_SHORT_PLURALS';
                break;
            case 'month':
                $ref = '.MONTHS_SHORT_PLURALS';
                break;
            case 'year':
                $ref = '.YEARS_SHORT_PLURALS';
                break;
        }

        if ($ref) {
            return _t(
                DBDate::class . $ref,
                "{count} {$format}|{count} {$format}s",
                ['count' => $span]
            );
        }

        return null;
    }

    protected function summariseDatabaseField($fieldType, $fieldName, $list, $listCount)
    {
        if (in_array($fieldType, self::NUMERIC_TYPES, true)) {
            return DBField::create_field($fieldType, $list->sum($fieldName))->Nice();
        }

        if (in_array($fieldType, self::DATE_TYPES, true)) {
            $min = $list->min($fieldName);
            $max = $list->max($fieldName);

            if ($min && $max) {
                $interval = (new DateTime($max))->diff(new DateTime($min));
                $parts = [];

                if ($interval->y >= 1) $parts[] = $this->getDateRangeLabel($interval->y, 'year');
                if ($interval->m >= 1) $parts[] = $this->getDateRangeLabel($interval->m, 'month');
                if ($interval->d >= 1) $parts[] = $this->getDateRangeLabel($interval->d, 'day');
                if ($interval->h >= 1) $parts[] = $this->getDateRangeLabel($interval->h, 'hour');
                if ($interval->i >= 1) $parts[] = $this->getDateRangeLabel($interval->i, 'min');
                if ($interval->s >= 1) $parts[] = $this->getDateRangeLabel($interval->s, 'sec');

                return DBField::create_field('Varchar', implode(', ', array_filter($parts)));
            }

            return null;
        }

        if (in_array($fieldType, self::BOOLEAN_TYPES, true)) {
            $true = 0;
            $false = 0;

            foreach ($list as $item) {
                $value = $item->$fieldName;
                $value = is_object($value) ? $value->Value : $value;
                if ($value) {
                    $true++;
                } else {
                    $false++;
                }
            }

            $trueText = DBField::create_field($fieldType, true)->Nice();
            $falseText = DBField::create_field($fieldType, false)->Nice();

            return DBField::create_field('Varchar', "$true $trueText, $false $falseText");
        }

        if (strpos($fieldType, 'Enum') === 0) {
            $dbObject = singleton($list->dataClass)->dbObject($fieldName);
            $enumValues = $dbObject->enumValues();
            $counts = array_fill_keys($enumValues, 0);

            foreach ($list as $item) {
                $value = $item->$fieldName;
                $value = is_object($value) ? $value->Value : $value;

                if (array_key_exists((string) $value, $counts)) {
                    $counts[(string) $value]++;
                }
            }

            if ($listCount > 0) {
                $parts = [];

                foreach ($enumValues as $enum) {
                    $parts[] = ($counts[$enum] ?? 0) . " $enum";
                }

                return DBField::create_field('Varchar', implode(', ', $parts));
            }
        }

        return null;
    }

    protected function summariseMethodField($fieldName, $list, $obj)
    {
        $sum = 0;
        $test = $obj->$fieldName();
        $type = 'Float';

        if (is_object($test)) {
            $type = ClassInfo::class_name($test);
            $test = $test->Value;
        }

        if (!is_numeric($test)) {
            return null;
        }

        foreach ($list as $item) {
            $value = $item->$fieldName();
            $sum += is_object($value) ? $value->Value : $value;
        }

        return DBField::create_field($type, $sum)->Nice();
    }

    protected function getSummaryValue($fieldName, $fieldType, $list, $listCount, $obj, $extraData = [])
    {
        $value = null;

        if (isset($fieldType)) {
            $value = $this->summariseDatabaseField($fieldType, $fieldName, $list, $listCount);
        }

        if ($value === null && ClassInfo::hasMethod($obj, $fieldName)) {
            $value = $this->summariseMethodField($fieldName, $list, $obj);
        }

        if ($value === null && !empty($extraData)) {
            foreach ($extraData as $extraField => $extraSpec) {
                if ($extraField === $fieldName) {
                    $value = $this->summariseDatabaseField($extraSpec, $extraField, $list, $listCount);
                    break;
                }
            }
        }

        if ($value === null) {
            $bits = explode('.', $fieldName);
            $last = $bits[count($bits) - 1];

            if ($last === 'Count') {
                $clause = preg_replace('/\.Count$/', '', $fieldName);
                $sum = 0;

                foreach ($list as $item) {
                    $sum += $item->$clause()->Count();
                }

                $value = DBField::create_field('Int', $sum)->Nice();
            } elseif (($thing = $last) && in_array($thing, ['Sum', 'Min', 'Max'], true)) {
                $field = preg_replace('/.*\.' . $thing . '\((.*)\)$/', '$1', $fieldName);
                $clause = preg_replace('/\.' . $thing . '\((.*)\)$/', '', $fieldName);
                $sum = 0;

                foreach ($list as $item) {
                    $sum += $item->$clause()->{$thing}($field);
                }

                $value = DBField::create_field('Int', $sum)->Nice();
            }
        }

        return $value;
    }

    public function getHTMLFragments($gridField): ?array
    {
        $cols = $gridField->getColumns();
        $list = $gridField->getList();

        foreach ($gridField->getComponents() as $item) {
            if ($item instanceof GridField_DataManipulator && !($item instanceof GridFieldPaginator)) {
                $list = $item->getManipulatedData($gridField, $list);
            }
        }

        if (empty($cols) || !$list) {
            return null;
        }

        $dataCount = 0;
        $summaryVals = ArrayList::create();
        $dataClass = $list->dataClass;
        $obj = singleton($dataClass);
        $db = $obj->getSchema()->databaseFields($dataClass);
        $extraData = ClassInfo::class_name($list) == ManyManyList::class ? $list->getExtraFields() : [];
        $listCount = $list->count();

        foreach ($cols as $col) {
            $val = $this->getSummaryValue($col, $db[$col] ?? null, $list, $listCount, $obj, $extraData);

            if ($val !== null) {
                $dataCount++;
            }

            $summaryVals->push(ArrayData::create(['Value' => $val ?? '']));
        }

        if ($dataCount) {
            $forTemplate = new ArrayData([
                'SummaryValues' => $summaryVals,
            ]);

            $template = SSViewer::get_templates_by_class($this, '', __CLASS__);
            return [
                $this->fragment => $forTemplate->renderWith($template),
            ];
        }

        return null;
    }
}
