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
    private $fragment;

    public function __construct($fragment = 'footer')
    {
        $this->fragment = $fragment;
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
        $dataCount = 0;
        $summaryVals = ArrayList::create();
        $obj = singleton($list->dataClass);
        $db = $obj->getSchema()->databaseFields($list->dataClass);
        $extraData = ClassInfo::class_name($list) == ManyManyList::class ? $list->getExtraFields() : [];

        // this function determines if we can do some math on the data.
        $tryMath = function (string $fieldType, string $fieldName) use ($list) {
            // here's a helper funtion to format nice date ranges...
            $pluralize = function ($span, $format) {
                $ref;
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
                // dunno what this is, abort.
                return null;
            };

            if (in_array($fieldType, [
                'Int', 'Float', 'Decimal', 'Currency',
                DBInt::class, DBFloat::class, DBDecimal::class, DBCurrency::class
            ]))
                // db field is a number of some sort, do a sum!
                return DBField::create_field($fieldType, $list->sum($fieldName))->Nice();
            elseif (in_array($fieldType, ['DateTime', 'Date', DBDate::class, DBDatetime::class])) {
                // db field is a date, do a range!
                $min = new DateTime($list->min($fieldName) ?: '');
                $max = new DateTime($list->max($fieldName) ?: '');
                $interval = $max->diff($min);
                $str = [];
                if ($interval->y >= 1) $str[] = $pluralize($interval->y, 'year');
                if ($interval->m >= 1) $str[] = $pluralize($interval->m, 'month');
                if ($interval->d >= 1) $str[] = $pluralize($interval->d, 'day');
                if ($interval->h >= 1) $str[] = $pluralize($interval->h, 'hour');
                if ($interval->i >= 1) $str[] = $pluralize($interval->i, 'min');
                if ($interval->s >= 1) $str[] = $pluralize($interval->s, 'sec');
                return DBField::create_field('Varchar', implode(', ', array_filter($str)));
            } elseif (in_array($fieldType, ['Boolean', DBBoolean::class])) {
                // db field is a boolean, do a count of true vs false
                $true = $list->filter($fieldName, true)->count();
                $false = $list->count() - $true;
                $trueText = DBField::create_field($fieldType, true)->Nice();
                $falseText = DBField::create_field($fieldType, false)->Nice();
                return DBField::create_field('Varchar', "$true $trueText, $false $falseText");
            } elseif (strpos($fieldType, 'Enum') === 0) {
                // db field is a list of possible values, do a count of each
                $obj = singleton($list->dataClass);
                $enums = $obj->dbObject($fieldName)->enumValues();
                $counts = [];
                foreach ($enums as $enum) {
                    $counts[] =  $list->filter($fieldName, $enum)->count(). " $enum";
                }
                return DBField::create_field('Varchar', implode(', ', $counts));
            }
            // dunno what this is, abort.
            return null;
        };

        foreach ($cols as $col) {
            $val = '';
            if (isset($db[$col]) && $val = $tryMath($db[$col], $col)) {
                // db field is a number of some sort, do math!
                $dataCount++;

            } elseif (ClassInfo::hasMethod($obj, $col)) {
                // it's a function, see if it returns a number we can sum!
                $sum = 0;
                $test = $obj->$col();
                $type = 'Float';

                if (is_object($test)) {
                    $type = ClassInfo::class_name($test);
                    $test = $test->Value;
                }
                if (is_numeric($test)) {
                    foreach ($list as $item) {
                        $val = $item->$col();
                        $sum += is_object($val) ? $val->Value : $val;
                    }
                    $val = DBField::create_field($type, $sum)->Nice();
                    $dataCount++;
                }
            } elseif (count($extraData)) {
                // catch manyManyExtraFields here, try some math...
                foreach ($extraData as $field => $spec) {
                    if ($field == $col && $val = $tryMath($spec, $field)) {
                        $dataCount++;
                        break;
                    }
                }
            } else {
                // catch any child Count() or Sum() statements
                $sum = 0;
                $bits = explode('.', $col);
                if ($bits[count($bits) - 1] == "Count") {
                    $clause = preg_replace('/\.Count$/', '', $col);
                    foreach ($list as $item) {
                        $sum += $item->$clause()->Count();
                    }
                    $val = DBField::create_field('Int', $sum)->Nice();
                    $dataCount++;
                } elseif (($thing = $bits[count($bits) - 1]) && in_array($thing, ["Sum", "Min", "Max"])) {
                    $field = preg_replace('/.*\.' . $thing . '\((.*)\)$/', '$1', $col);
                    $clause = preg_replace('/\.' . $thing . '\((.*)\)$/', '', $col);
                    foreach ($list as $item) {
                        $sum += $item->$clause()->{$thing}($field);
                    }
                    $val = DBField::create_field('Int', $sum)->Nice();
                    $dataCount++;
                }
            }

            $summaryVals->push(ArrayData::create(['Value' => $val]));

        }

        if ($dataCount) {
            $forTemplate = new ArrayData(array(
                'SummaryValues' => $summaryVals
            ));

            $template = SSViewer::get_templates_by_class($this, '', __CLASS__);
            return array(
                $this->fragment => $forTemplate->renderWith($template)
            );
        }
        return null;
    }
}
