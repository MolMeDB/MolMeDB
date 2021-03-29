
function show_sunburst(data, target_id)
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
    
        // define data fields
        chart.dataFields.value = "value";
        chart.dataFields.name = "name";;
        chart.dataFields.children = "children";
    
    
        var level0SeriesTemplate = new am4plugins_sunburst.SunburstSeries();
        level0SeriesTemplate.hiddenInLegend = false;
        chart.seriesTemplates.setKey("0", level0SeriesTemplate)
        level0SeriesTemplate.labels.template.text = "{category}";
        level0SeriesTemplate.slices.template.tooltipText = "{category}";
    
        // this makes labels to be hidden if they don't fit
        level0SeriesTemplate.labels.template.truncate = true;
        level0SeriesTemplate.labels.template.hideOversized = true;
    
        level0SeriesTemplate.labels.template.adapter.add("rotation", function(rotation, target) {
            target.maxWidth = target.dataItem.slice.radius - target.dataItem.slice.innerRadius - 10;
            target.maxHeight = Math.abs(target.dataItem.slice.arc * (target.dataItem.slice.innerRadius + target.dataItem.slice.radius) / 2 * am4core.math.RADIANS);
    
            return rotation;
        })

        level0SeriesTemplate.slices.template.events.on('hit', function(ev)
        {
            var el_id = ev.target.dataItem.sunburstDataItem._dataContext.id_element;
            var last = ev.target.dataItem.sunburstDataItem._dataContext.last;

            if(!last)
            {
                return;
            }
            
            if(!el_id)
            {
                console.log("Element id not found");
                return false;
            }

            var target_id = "#target_" + el_id;
            var target = $(target_id);

            if(!target)
            {
                console.log('Target not found.');
                return;
            }

            $(target).click();
        });
    
    
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
        chart.legend = new am4charts.Legend();
    
    }); 
}
