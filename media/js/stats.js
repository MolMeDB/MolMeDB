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

    // Create axes
    var categoryAxis = chart.xAxes.push(new am4charts.CategoryAxis());
    categoryAxis.dataFields.category = "membrane";
    categoryAxis.title.text = "Membranes";

    // First value axis
    var valueAxis = chart.yAxes.push(new am4charts.ValueAxis());
    valueAxis.title.text = "Total";

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
    series.dataFields.valueY = "count_interactions";
    series.dataFields.categoryX = "membrane";
    series.name = "Total interactions";
    series.tooltipText = "{name}: [bold]{valueY}[/]";

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

    if(axisBreaks)
    {
        for(var i = 0; i < axisBreaks.interactions.length; i++)
        {
            var ab = axisBreaks.interactions[i];

            var axisBreak = valueAxis.axisBreaks.create();
            axisBreak.startValue=ab.startValue;
            axisBreak.endValue=ab.endValue;
            axisBreak.breakSize=ab.breakSize;
        }

        // for(var i = 0; i < axisBreaks.substances.length; i++)
        // {
        //     var ab = axisBreaks.substances[i];

        //     var axisBreak = valueAxis2.axisBreaks.create();
        //     axisBreak.startValue=ab.startValue;
        //     axisBreak.endValue=ab.endValue;
        //     axisBreak.breakSize=ab.breakSize;

        // }
    }

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

    // First value axis
    var valueAxis = chart.yAxes.push(new am4charts.ValueAxis());
    valueAxis.title.text = "Total";

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
    series.dataFields.valueY = "count_interactions";
    series.dataFields.categoryX = "method";
    series.name = "Total interactions";
    series.tooltipText = "{name}: [bold]{valueY}[/]";

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

    if(axisBreaks)
    {
        for(var i = 0; i < axisBreaks.interactions.length; i++)
        {
            var ab = axisBreaks.interactions[i];

            var axisBreak = valueAxis.axisBreaks.create();
            axisBreak.startValue=ab.startValue;
            axisBreak.endValue=ab.endValue;
            axisBreak.breakSize=ab.breakSize;
        }

        // for(var i = 0; i < axisBreaks.substances.length; i++)
        // {
        //     var ab = axisBreaks.substances[i];

        //     var axisBreak = valueAxis2.axisBreaks.create();
        //     axisBreak.startValue=ab.startValue;
        //     axisBreak.endValue=ab.endValue;
        //     axisBreak.breakSize=ab.breakSize;

        // }
    }

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
    categoryAxis.dataFields.category = "identifier";
    categoryAxis.title.text = "External databases";

    // First value axis
    var valueAxis = chart.yAxes.push(new am4charts.ValueAxis());
    valueAxis.title.text = "Total substances";

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
        var ab = axisBreak[0];

        var axisBreak = valueAxis.axisBreaks.create();
        axisBreak.startValue=ab.startValue;
        axisBreak.endValue=ab.endValue;
        axisBreak.breakSize=ab.breakSize;
    }

    // Add legend
    chart.legend = new am4charts.Legend();

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
    pieSeries.slices.template.strokeWidth = 3;
    pieSeries.slices.template.strokeOpacity = 1;

    // This creates initial animation
    pieSeries.hiddenState.properties.opacity = 1;
    pieSeries.hiddenState.properties.endAngle = -90;
    pieSeries.hiddenState.properties.startAngle = -90;
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
    // axis break
    // var axisBreak = valueAxis.axisBreaks.create();
    // axisBreak.startValue = 2100;
    // axisBreak.endValue = 22900;
    // axisBreak.breakSize = 0.005;

    // make break expand on hover
    // var hoverState = axisBreak.states.create("hover");
    // hoverState.properties.breakSize = 1;
    // hoverState.properties.opacity = 0.1;
    // hoverState.transitionDuration = 1500;

    // axisBreak.defaultState.transitionDuration = 1000;
    /*
    // this is exactly the same, but with events
    axisBreak.events.on("over", () => {
    axisBreak.animate(
        [{ property: "breakSize", to: 1 }, { property: "opacity", to: 0.1 }],
        1500,
        am4core.ease.sinOut
    );
    });
    axisBreak.events.on("out", () => {
    axisBreak.animate(
        [{ property: "breakSize", to: 0.005 }, { property: "opacity", to: 1 }],
        1000,
        am4core.ease.quadOut
    );
    });*/

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