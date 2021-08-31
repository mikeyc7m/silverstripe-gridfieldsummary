# Gridfield Summary for Silverstripe
Adds a footer to gridfields with sums &amp; counts.
## Usage:
Add it to your grid field configurations, before your paginator.

`$gridField->getConfig()->addComponent(new GridFieldSummaryFooter(), GridFieldPaginator::class);`
## Notes:
The summary tries to sum or count your records, so be cautious about applying it to large datasets - it may slow down your system.
