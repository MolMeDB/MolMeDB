
/**
 * Creates chart for adding data
 * 
 * 
 * @param {*} data 
 * @param {*} divID 
 * @param {*} parameters 
 */
function makeAddingLineChart(data, divID, parameters = {}, maxValue = null)
{
    if(!$("#" + divID))
    {
        console.log('Div not found.');
        return;
    }

    am4core.useTheme(am4themes_animated);

    // Create chart instance
    var chart = am4core.create(divID, am4charts.XYChart);

    chart.data = data;

    chart.zoomOutButton.align = "left";
    chart.zoomOutButton.valign = "top";

    // Create axes
    var xAxis = chart.xAxes.push(new am4charts.DateAxis());
    xAxis.renderer.grid.template.location = 0;
    xAxis.renderer.minGridDistance = 50;
    // var xAxis = chart.xAxes.push(new am4charts.CategoryAxis());
    // xAxis.dataFields.category = "citation";

    var yAxis = chart.yAxes.push(new am4charts.ValueAxis());

    if(maxValue)
    {
        yAxis.min = 0;
        yAxis.max = parseInt(maxValue);
        yAxis.strictMinMax = true;
    }
    
    if(parameters.axisBreaks)
    {
        for(var i = 0; i < parameters.axisBreaks.length; i++)
        {
            var ab = parameters.axisBreaks[i];

            var axisBreak = yAxis.axisBreaks.create();
            axisBreak.startValue=ab.startValue;
            axisBreak.endValue=ab.endValue;
            axisBreak.breakSize=ab.breakSize;
        }
    }

    // Create series
    function createSeries(field, name) {
        var series = chart.series.push(new am4charts.LineSeries());
        series.dataFields.valueY = field;
        series.dataFields.dateX = "date";
        series.name = name;
        series.tooltipText = "[b]{valueY}[/]";
        series.strokeWidth = 2;
        
        series.smoothing = "monotoneX";
        
        var bullet = series.bullets.push(new am4charts.CircleBullet());
        bullet.circle.stroke = am4core.color("#fff");
        bullet.circle.strokeWidth = 2;
        
        return series;
    }

    for(var i = 0; i < parameters.series.length; i++)
    {
        var s = parameters.series[i];
        createSeries(s.attribute, s.label);
    }

    chart.legend = new am4charts.Legend();
    chart.cursor = new am4charts.XYCursor();
}


function makeLineChart(data, divID, parameters = {})
{
    if(!$("#" + divID))
    {
        console.log('Div not found.');
        return;
    }

    am4core.useTheme(am4themes_animated);

    // Create chart instance
    var chart = am4core.create(divID, am4charts.XYChart);

    chart.data = data;

    // Create axes
    var xAxis = chart.xAxes.push(new am4charts.DateAxis());
    xAxis.renderer.grid.template.location = 0;
    xAxis.renderer.minGridDistance = 50;
    // var xAxis = chart.xAxes.push(new am4charts.CategoryAxis());
    // xAxis.dataFields.category = "citation";

    var yAxis = chart.yAxes.push(new am4charts.ValueAxis());
    
    if(parameters.axisBreaks)
    {
        for(var i = 0; i < parameters.axisBreaks.length; i++)
        {
            var ab = parameters.axisBreaks[i];

            var axisBreak = yAxis.axisBreaks.create();
            axisBreak.startValue=ab.startValue;
            axisBreak.endValue=ab.endValue;
            axisBreak.breakSize=ab.breakSize;

            // var hoverState = axisBreak.states.create("hover");
            // hoverState.properties.breakSize = 1;
            // hoverState.properties.opacity = 0.1;
            // hoverState.transitionDuration = 1500;

            // axisBreak.defaultState.transitionDuration = 1000;
        }
    }

    // Create series
    function createSeries(field, name) {
        var series = chart.series.push(new am4charts.LineSeries());
        series.dataFields.valueY = field;
        series.dataFields.dateX = "date";
        series.name = name;
        series.tooltipText = "[b]{valueY}[/]";
        series.strokeWidth = 2;
        
        series.smoothing = "monotoneX";
        
        var bullet = series.bullets.push(new am4charts.CircleBullet());
        bullet.circle.stroke = am4core.color("#fff");
        bullet.circle.strokeWidth = 2;
        
        return series;
    }

    for(var i = 0; i < parameters.series.length; i++)
    {
        var s = parameters.series[i];
        createSeries(s.attribute, s.label);
    }

    chart.legend = new am4charts.Legend();
    chart.cursor = new am4charts.XYCursor();
}

/**
 * Membranes stats
 * 
 * @param target
 * @param data
 * @param axisBreaks
 * 
 */
function make_membrane_total_column_chart(target, data, axisBreaks = null, maxValue = null)
{
    if(!$("#" + target))
    {
        console.log('Div not found.');
        return;
    }

    am4core.useTheme(am4themes_animated);
    // am4core.useTheme(am4themes_kelly);

    var chart = am4core.create(target, am4charts.XYChart);

    chart.data = data;
    // chart.cursor.maxTooltipDistance = -1;

    // Create axes
    var categoryAxis = chart.xAxes.push(new am4charts.CategoryAxis());
    categoryAxis.dataFields.category = "membrane";
    categoryAxis.title.text = "Membranes";
    categoryAxis.renderer.minGridDistance = 10;

    // First value axis
    var valueAxis = chart.yAxes.push(new am4charts.ValueAxis());
    valueAxis.title.text = "Total";
    valueAxis.logarithmic = true;

    var label = categoryAxis.renderer.labels.template;
    // label.truncate = true;
    label.maxWidth = 150;
    label.tooltipText = "{category}";

    categoryAxis.events.on("sizechanged", function(ev) {
        var axis = ev.target;
        var cellWidth = axis.pixelWidth / (axis.endIndex - axis.startIndex);
        if (cellWidth < axis.renderer.labels.template.maxWidth) {
            axis.renderer.labels.template.rotation = -90;
            axis.renderer.labels.template.horizontalCenter = "right";
            axis.renderer.labels.template.verticalCenter = "middle";
        }
        else {
            axis.renderer.labels.template.rotation = 0;
            axis.renderer.labels.template.horizontalCenter = "middle";
            axis.renderer.labels.template.verticalCenter = "top";
        }
    });

    if(maxValue)
    {
        valueAxis.min = 0;
        valueAxis.max = maxValue;
        valueAxis.strictMinMax = true;
    }

    // First series
    var series = chart.series.push(new am4charts.ColumnSeries());
    series.dataFields.valueY = "count_interactions";
    series.dataFields.id = "id";
    series.dataFields.categoryX = "membrane";
    series.name = "Total interactions";
    series.tooltipHTML = "<div class='column justify-content-center align-items-center'><div>{name}: <b>{valueY}</div></b><button class='btn btn-sm btn-warning' onclick='redirect(\"browse/membranes?target={id}\");'>Membrane detail</button></div>";
    series.tooltip.label.interactionsEnabled = true;
    series.tooltip.pointerOrientation = "vertical";

    // Second series
    var series2 = chart.series.push(new am4charts.LineSeries());
    series2.dataFields.valueY = "count_substances";
    series2.dataFields.categoryX = "membrane";
    series2.name = "Total substances";
    series2.tooltipText = "{name}: [bold]{valueY}[/]";
    series2.strokeWidth = 3;
    series2.yAxis = valueAxis;

    var bullet = series2.bullets.push(new am4charts.CircleBullet());
    bullet.circle.stroke = am4core.color("#fff");
    bullet.circle.strokeWidth = 1;

    // Add simple vertical scrollbar
    chart.scrollbarY = new am4core.Scrollbar();

    // Add horizotal scrollbar with preview
    var scrollbarX = new am4charts.XYChartScrollbar();
    scrollbarX.series.push(series);
    chart.scrollbarX = scrollbarX;

    // Add legend
    chart.legend = new am4charts.Legend();

    // Add cursor
    chart.cursor = new am4charts.XYCursor();
}

/**
 * Methods stats
 * 
 * @param target
 * @param data
 * @param axisBreaks
 * 
 */
function make_method_total_column_chart(target, data, axisBreaks = null, maxValue = null)
{
    if(!$("#" + target))
    {
        console.log('Div not found.');
        return;
    }

    am4core.useTheme(am4themes_animated);
    // am4core.useTheme(am4themes_kelly);

    var chart = am4core.create(target, am4charts.XYChart);

    chart.data = data;

    // Create axes
    var categoryAxis = chart.xAxes.push(new am4charts.CategoryAxis());
    categoryAxis.dataFields.category = "method";
    categoryAxis.title.text = "Methods";
    categoryAxis.renderer.minGridDistance = 10;

    var label = categoryAxis.renderer.labels.template;
    // label.truncate = true;
    label.maxWidth = 150;
    label.tooltipText = "{category}";

    categoryAxis.events.on("sizechanged", function(ev) {
        var axis = ev.target;
        var cellWidth = axis.pixelWidth / (axis.endIndex - axis.startIndex);
        if (cellWidth < axis.renderer.labels.template.maxWidth) {
            axis.renderer.labels.template.rotation = -90;
            axis.renderer.labels.template.horizontalCenter = "right";
            axis.renderer.labels.template.verticalCenter = "middle";
        }
        else {
            axis.renderer.labels.template.rotation = 0;
            axis.renderer.labels.template.horizontalCenter = "middle";
            axis.renderer.labels.template.verticalCenter = "top";
        }
    });

    // First value axis
    var valueAxis = chart.yAxes.push(new am4charts.ValueAxis());
    valueAxis.title.text = "Total";
    valueAxis.logarithmic = true;

    if(maxValue)
    {
        valueAxis.min = 0;
        valueAxis.max = maxValue;
        valueAxis.strictMinMax = true;
    }

    // First series
    var series = chart.series.push(new am4charts.ColumnSeries());
    series.dataFields.valueY = "count_interactions";
    series.dataFields.categoryX = "method";
    series.dataFields.id = "id";
    series.name = "Total interactions";
    series.tooltipHTML = "<div class='column justify-content-center align-items-center'><div>{name}: <b>{valueY}</div></b><button class='btn btn-sm btn-warning' onclick='redirect(\"browse/methods?target={id}\");'>Method detail</button></div>";
    series.tooltip.label.interactionsEnabled = true;
    series.tooltip.pointerOrientation = "vertical";

    // Second series
    var series2 = chart.series.push(new am4charts.LineSeries());
    series2.dataFields.valueY = "count_substances";
    series2.dataFields.categoryX = "method";
    series2.name = "Total substances";
    series2.tooltipText = "{name}: [bold]{valueY}[/]";
    series2.strokeWidth = 3;
    series2.yAxis = valueAxis;

    var bullet = series2.bullets.push(new am4charts.CircleBullet());
    bullet.circle.stroke = am4core.color("#fff");
    bullet.circle.strokeWidth = 1;

    // Add simple vertical scrollbar
    chart.scrollbarY = new am4core.Scrollbar();

    // Add horizotal scrollbar with preview
    var scrollbarX = new am4charts.XYChartScrollbar();
    scrollbarX.series.push(series);
    chart.scrollbarX = scrollbarX;

    // Add legend
    chart.legend = new am4charts.Legend();

    // Add cursor
    chart.cursor = new am4charts.XYCursor();
}

/**
 * Identifiers stats
 * 
 * @param target
 * @param data
 * @param axisBreaks
 * 
 */
function make_identifiers_chart(target, data, axisBreak = null, maxValue = null)
{
    if(!$("#" + target))
    {
        console.log('Div not found.');
        return;
    }

    am4core.useTheme(am4themes_animated);
    // am4core.useTheme(am4themes_kelly);

    var chart = am4core.create(target, am4charts.XYChart);

    chart.data = data;

    // Create axes
    var categoryAxis = chart.xAxes.push(new am4charts.CategoryAxis());
    categoryAxis.renderer.grid.template.location = 0;
    categoryAxis.renderer.minGridDistance = 30; 
    categoryAxis.dataFields.category = "identifier";
    categoryAxis.title.text = "External databases";

    var label = categoryAxis.renderer.labels.template;
    label.truncate = true;
    label.maxWidth = 200;
    label.tooltipText = "{category}";

    categoryAxis.events.on("sizechanged", function(ev) {
        var axis = ev.target;
          var cellWidth = axis.pixelWidth / (axis.endIndex - axis.startIndex);
          if (cellWidth < axis.renderer.labels.template.maxWidth) {
            axis.renderer.labels.template.rotation = -45;
            axis.renderer.labels.template.horizontalCenter = "right";
            axis.renderer.labels.template.verticalCenter = "middle";
          }
          else {
            axis.renderer.labels.template.rotation = 0;
            axis.renderer.labels.template.horizontalCenter = "middle";
            axis.renderer.labels.template.verticalCenter = "top";
          }
        });

    // First value axis
    var valueAxis = chart.yAxes.push(new am4charts.ValueAxis());
    // valueAxis.title.text = "Total substances";

    if(maxValue)
    {
        valueAxis.min = 0;
        valueAxis.max = maxValue;
        valueAxis.strictMinMax = true;
    }
    // Second value axis
    // var valueAxis2 = chart.yAxes.push(new am4charts.ValueAxis());
    // valueAxis2.title.text = "Total substances";
    // valueAxis2.renderer.opposite = true;

    // First series
    var series = chart.series.push(new am4charts.ColumnSeries());
    series.dataFields.valueY = "count";
    series.dataFields.categoryX = "identifier";
    series.name = "Total substances";
    series.tooltipText = "{name}: [bold]{valueY}[/]";

    // // Add simple vertical scrollbar
    // chart.scrollbarY = new am4core.Scrollbar();

    // // Add horizotal scrollbar with preview
    // var scrollbarX = new am4charts.XYChartScrollbar();
    // scrollbarX.series.push(series);
    // chart.scrollbarX = scrollbarX;

    if(axisBreak)
    {
        var ab = axisBreak;

        var axisBreak = valueAxis.axisBreaks.create();
        axisBreak.startValue=ab.startValue;
        axisBreak.endValue=ab.endValue;
        axisBreak.breakSize=ab.breakSize;
    }

    // Add legend
    // chart.legend = new am4charts.Legend();

    // Add cursor
    chart.cursor = new am4charts.XYCursor();
}

function makeInteractionsPie(divID, data)
{
    am4core.useTheme(am4themes_animated);

    var chart = am4core.create(divID, am4charts.PieChart);

    chart.data = data;

    // Add and configure Series
    var pieSeries = chart.series.push(new am4charts.PieSeries());
    pieSeries.dataFields.value = "count";
    pieSeries.dataFields.category = "name";
    pieSeries.slices.template.stroke = am4core.color("#fff");
    pieSeries.slices.template.strokeWidth = 1;
    pieSeries.slices.template.strokeOpacity = 1;
    pieSeries.labels.template.disabled = true;
    pieSeries.ticks.template.disabled = true;

    // This creates initial animation
    pieSeries.hiddenState.properties.opacity = 1;
    pieSeries.hiddenState.properties.endAngle = -90;
    pieSeries.hiddenState.properties.startAngle = -90;

    // Add a legend
    chart.legend = new am4charts.Legend();  
}

function makeColumnChart(data, divID, parameters = {})
{
    if(!$("#" + divID))
    {
        console.log('Div not found.');
        return;
    }

    am4core.useTheme(am4themes_animated);

    var chart = am4core.create(divID, am4charts.XYChart);
    chart.hiddenState.properties.opacity = 0; // this creates initial fade-in

    chart.data = data;

    var categoryAxis = chart.xAxes.push(new am4charts.CategoryAxis());
    categoryAxis.renderer.grid.template.location = 0;
    categoryAxis.dataFields.category = "citation";

    var valueAxis = chart.yAxes.push(new am4charts.ValueAxis());
    // valueAxis.min = 0;
    // valueAxis.max = 24000;
    // valueAxis.strictMinMax = true;
    valueAxis.renderer.minGridDistance = 30;

    if(parameters.axisBreaks)
    {
        for(var i = 0; i < parameters.axisBreaks.length; i++)
        {
            var ab = parameters.axisBreaks[i];

            var axisBreak = valueAxis.axisBreaks.create();
            axisBreak.startValue=ab.startValue;
            axisBreak.endValue=ab.endValue;
            axisBreak.breakSize=ab.breakSize;

            var hoverState = axisBreak.states.create("hover");
            hoverState.properties.breakSize = 1;
            hoverState.properties.opacity = 0.1;
            hoverState.transitionDuration = 1500;

            axisBreak.defaultState.transitionDuration = 1000;
        }
    }


    var series = chart.series.push(new am4charts.ColumnSeries());
    series.dataFields.categoryX = "citation";
    series.dataFields.valueY = "count";
    series.columns.template.tooltipText = "{valueY.value}";
    series.columns.template.tooltipY = 0;

    series.columns.template.strokeOpacity = 0;
    

    // as by default columns of the same series are of the same color, we add adapter which takes colors from chart.colors color set
    series.columns.template.adapter.add("fill", (fill, target) => {
    return chart.colors.getIndex(target.dataItem.index);
    });
}

function show_sunburst(data, target_id, clickable = false, callback = null)
{
    am4core.ready(function() {
        // Themes begin
        am4core.useTheme(am4themes_animated);
        // Themes end
    
        // create chart
        var chart = am4core.create(target_id, am4plugins_sunburst.Sunburst);
        chart.padding(0,0,0,0);
        chart.radius = am4core.percent(98);
    
        chart.data = data;
    
        chart.colors.step = 2;
        chart.fontSize = 11;
        chart.innerRadius = am4core.percent(10);
        chart.cursor = 'pointer';
    
        // define data fields
        chart.dataFields.value = "value";
        chart.dataFields.name = "name";
        chart.dataFields.children = "children";
    
    
        var level0SeriesTemplate = new am4plugins_sunburst.SunburstSeries();
        level0SeriesTemplate.hiddenInLegend = false;
        chart.seriesTemplates.setKey("0", level0SeriesTemplate)
        level0SeriesTemplate.labels.template.text = "{category}";
        level0SeriesTemplate.slices.template.tooltipText = "[bold]{category}[/]: {value} interactions";
    
        // this makes labels to be hidden if they don't fit
        level0SeriesTemplate.labels.template.truncate = true;
        level0SeriesTemplate.labels.template.hideOversized = true;

        // cursor
        level0SeriesTemplate.slices.template.cursorOverStyle = am4core.MouseCursorStyle.pointer;
    
        level0SeriesTemplate.labels.template.adapter.add("rotation", function(rotation, target) {
            target.maxWidth = target.dataItem.slice.radius - target.dataItem.slice.innerRadius - 10;
            target.maxHeight = Math.abs(target.dataItem.slice.arc * (target.dataItem.slice.innerRadius + target.dataItem.slice.radius) / 2 * am4core.math.RADIANS);
    
            return rotation;
        });

        
        if(clickable)
        {
            if(!callback)
            {
                level0SeriesTemplate.slices.template.events.on('hit', function(ev)
                {
                    var el_id = ev.target.dataItem.sunburstDataItem._dataContext.id_element;
                    var last = ev.target.dataItem.sunburstDataItem._dataContext.last;

                    last = last ? 1 : 0;

                    if(!el_id)
                    {
                        console.log("Element id not found");
                        return false;
                    }

                    redirect('browse/transporters/' + el_id + '/' + last);
                });
            }
            else
            {
                level0SeriesTemplate.slices.template.events.on('hit', callback);
            }
        }
    
        var level1SeriesTemplate = level0SeriesTemplate.clone();
        chart.seriesTemplates.setKey("1", level1SeriesTemplate)
        level1SeriesTemplate.fillOpacity = 0.75;
        level1SeriesTemplate.hiddenInLegend = true;
        // level1SeriesTemplate.labels.template.text = "{category}";
    
        var level2SeriesTemplate = level0SeriesTemplate.clone();
        chart.seriesTemplates.setKey("2", level2SeriesTemplate)
        level2SeriesTemplate.fillOpacity = 0.5;
        level2SeriesTemplate.hiddenInLegend = true;
        // level2SeriesTemplate.labels.template.text = "{category}";
        level2SeriesTemplate.labels.template.fill = am4core.color("#000");

        var level3SeriesTemplate = level0SeriesTemplate.clone();
        chart.seriesTemplates.setKey("3", level3SeriesTemplate)
        level3SeriesTemplate.fillOpacity = 0.75;
        level3SeriesTemplate.hiddenInLegend = true;

        var level4SeriesTemplate = level0SeriesTemplate.clone();
        chart.seriesTemplates.setKey("4", level4SeriesTemplate)
        level4SeriesTemplate.fillOpacity = 0.75;
        level4SeriesTemplate.hiddenInLegend = true;
        // level1SeriesTemplate.labels.template.text = "{category}";
    
        chart.legend = new am4charts.Legend();

    }); 
}