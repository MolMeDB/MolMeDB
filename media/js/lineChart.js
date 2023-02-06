var config;
var data;
var res = [];
var dataset = [];
var export_data = [];

var visibleCharts = {};

/**
 * Renders chart to the target. If exists and clear=false, then will be just added
 * 
 * @param {object} data 
 * @param {string} targetID 
 * @param {boolean} clear 
 */
function createLineChart(data, targetID, clear = false)
{
    if(typeof(data) == 'string')
    {
        data = JSON.parse(data);
    }

    console.log(data);

    // Already exists? Add chart to existing one
    if(targetID in visibleCharts && !clear)
    {

    }
    else // Create new one
    {
        // Get yValue series keys
        if(data.length)
        {
            let d = data[0];
            ys = Object.keys(d);

            const index = ys.indexOf("x");
            if (index > -1) { // only splice array when item is found
                ys.splice(index, 1); // 2nd parameter means remove one item only
            }
            else
            {
                console.log("Cannot generate chart. X value not found.");
                return;
            }
        }
        else
        {
            console.log("No data found...");
            return;
        }

        am5.ready(function() {
            // Create root element
            // https://www.amcharts.com/docs/v5/getting-started/#Root_element
            var root = am5.Root.new(targetID);
            
            // Set themes
            // https://www.amcharts.com/docs/v5/concepts/themes/
            root.setThemes([
              am5themes_Animated.new(root)
            ]);
            
            
            // Create chart
            // https://www.amcharts.com/docs/v5/charts/xy-chart/
            var chart = root.container.children.push(am5xy.XYChart.new(root, {
              panX: false,
              panY: false,
              wheelX: null,
              wheelY: null,
              pinchZoomX:false
            }));
            
            
            // Add cursor
            // https://www.amcharts.com/docs/v5/charts/xy-chart/cursor/
            var cursor = chart.set("cursor", am5xy.XYCursor.new(root, {
              behavior: "none"
            }));
            cursor.lineY.set("visible", false);
            
            
            // Create axes
            // https://www.amcharts.com/docs/v5/charts/xy-chart/axes/
            var xAxis = chart.xAxes.push(am5xy.ValueAxis.new(root, {
              renderer: am5xy.AxisRendererX.new(root, {}),
              tooltip: am5.Tooltip.new(root, {}),
              min: 0,
            //   tooltipNumberFormat: "#.##",
              extraTooltipPrecision: 2
            }));
            
            var yAxis = chart.yAxes.push(am5xy.ValueAxis.new(root, {
              renderer: am5xy.AxisRendererY.new(root, {})
            }));

            let allSeries = [];

            for(v in ys)
            {
                // Add series
                // https://www.amcharts.com/docs/v5/charts/xy-chart/series/
                allSeries.push(chart.series.push(am5xy.LineSeries.new(root, {
                    name: "Series",
                    xAxis: xAxis,
                    yAxis: yAxis,
                    valueYField: ys[v],
                    valueXField: "x",
                    tooltip: am5.Tooltip.new(root, {
                        labelText: "{valueX} nm: {valueY} kcal/mol"
                    })
                })));
            }
            
            
            // Set data and show series
            for(k in allSeries)
            {
                allSeries[k].data.setAll(data);
                allSeries[k].appear(1000);
            }

            // Make stuff animate on load
            // https://www.amcharts.com/docs/v5/concepts/animations/
            chart.appear(1000, 100);

            visibleCharts[targetID] = {
                dataset: data,
                chartRoot: chart
            };
        }); // end am5.ready()
    }
}









function findNearestIndex(array, point){
    var count = array.length;
    var i = 0;
    while(i<count){
        if(array[i]>point)
            return i;
        i++;
    }
    return -1;
}

function fillValues(array, index){
    if(index === 0){
        return array[0];
    }
    
    var l = ((index - 5) < 0) ? 0 : index - 5;
    var r = ((index + 5) > array.length-1) ? array.length-1 : index + 5;
    var res = [];
    
    while(l<=r){
        res.push(array[l]);
        l++;
    }
    return res;
}

var fun = function (){};
var myChart = fun;


function loadLineChart(data){
    console.log(data);
    var labelsSet = [];
    var color = ['rgba(50,230,21,1)', 'rgba(255,99,132,1)', 'rgba(80,99,210,1)', 'rgba(255,220,100,1)', 'rgba(0,50,255,1)' , 'rgba(229,146,0,1)', 'rgba(150,0,255,1)', 'rgba(110,200,132,1)', 
                 'rgba(215,99,255,1)', 'rgba(25,99,160,1)', 'rgba(255,150,0,1)', 'rgba(0,255,132,1)', 'rgba(100,150,0,1)', 'rgba(20,130,250,1)'];
    export_data = [];
    export_data[0] = [];
    export_data[0][0] = 'Distance [nm]';
    
    for(var i=0; i<36; i++)
    {
        labelsSet[i] = i/10;
        export_data[i+1] = [];
        export_data[i+1][0] = i/10;
    }
    
    var keys = Object.keys(data);
    var count = keys.length;

    dataset = [];
    
    for(var i=0; i<count; i++)
    {
        var key = keys[i];
        var label = {
            membrane: data[key].membrane_cam ? data[key].membrane_cam : data[key].membrane,
            method: data[key].method_cam ? data[key].method_cam : data[key].method,
        };
        
        dataset[i] = {};
        dataset[i].label = label.method + "(" + label.membrane + ")";
        dataset[i].borderWidth = 1;
        dataset[i].pointRadius = 4;
        dataset[i].backgroundColor = 'rgba(255,255,255,0.1)';
        dataset[i].borderColor = color[i].toString();
        export_data[0][i+1] = dataset[i].label + " [kcal/mol]";
        
        var index; var x_main = []; var y_main = []; var X = []; var Y = []; var values = [];
        
        var size = data[key].data.length;
        for(var j=0; j<size; j++){ //Copying values of free energy profile to the arrays
            x_main[j] = data[key].data[j].distance;
            y_main[j] = data[key].data[j].energy;
        }
        
        if(x_main.length === 1){
            continue;
        }
        
        for (var j = 0; j < 36; j++) { //Interpolation point by point
            if (j === 0) {
                values[j] = y_main[0];
                export_data[j+1][i+1] = y_main[0];
                continue;
            }
            index = findNearestIndex(x_main, labelsSet[j]);
            if (index === -1){ //If value is out of array
                export_data[j+1][i+1] = 0;
                values[j] = 0;
            }
            else {
                X = fillValues(x_main, index);
                Y = fillValues(y_main, index);
                values[j] = nevillesIteratedInterpolation(labelsSet[j], X, Y);
                values[j] = Math.round(values[j] * 10000) / 10000;
                export_data[j+1][i+1] = values[j];
            }
        }

        console.log(values);

        dataset[i].data = values;
        dataset[i].spanGaps = true;
    }
    
    
    data = {labels: labelsSet,
            datasets: dataset
    };
    config = {      type: 'line',
                    data: data,
                    options: {
                        responsive: true,
                        events: ['mousemove'],
                        tooltips: {
                            mode: 'x'
                        },
                        legend: {
                            display: true,
                            labels: {
                            fontColor: 'rgb(0, 0, 10)'
                            }
                        },
                        title: {
                            display: false,
                            position: 'top',
                            text: 'Distance [nm]'
                        },
                        scales: {
                            yAxes: [{
                                ticks: {
                                    beginAtZero: true
                                },
                                scaleLabel: {
                                        display: true,
                                        labelString: 'Free energy [kcal / mol]',
                                        padding: 10
                                    },
                                gridLines: {
                                        
                                    }
                            }],
                            xAxes: [{
                                    ticks: {
                                        beginAtZero: true,
                                        autoSkip: false,
                                        callback: function(dataLabel, index) {
                                                return  (dataLabel % 1 === 0 || dataLabel === 3.5) ? dataLabel : '';
                                        }
                                    },
                                    scaleLabel: {
                                        display: true,
                                        labelString: 'Distance [nm]'
                                    },
                                    gridLines: {
                                        display: true
                                    }
                                    
                            }]
                        }
                    }
                }
    renderChart();
}


function renderChart(){
    if(typeof myChart != 'function'){
        myChart.destroy();
    }
    var ctx = document.getElementById("myChart").getContext('2d');
    myChart = new Chart(ctx, config);
}

// Předělat do PHP a načítat zrovna

// function loadChartTable(id)
// {
//     var data = document.querySelectorAll("div.membraneTab");
//     var size = data.length;
//     var list = [];
//     var res;
    
//     for (i = 0; i < size; i++) {
//         data[i].onclick = function() {
//             if(this.className == "active"){
//                 this.className = "not-active";
//                 loadLineChart(id);
//             }
//             else if (this.className == "not-active"){
//                 this.className = "active";
//                 loadLineChart(id);
//             }
//         }
//     }


//     for (var i = 0; i<size; i++)
//     {
//         var temp = data[i].childNodes[1].value;
//         temp = temp.split(';');
//         list.push({
//             idMembrane: temp[1],
//             idMethod: temp[0]
//         })
//     }

//     list = JSON.stringify(list);

//     var ajax_data = ajax_request('detail/getEnergyData', {list: list, id: id}, "POST");
    
//    for(var i=0; i<size; i++)
//    {
//        if (ajax_data[i] != 0)
//        {
//             data[i].setAttribute("class", "not-active");
//             data[i].setAttribute('style', 'display: inline-block;');
//        }
//    }
   
    
//     data = document.getElementsByClassName("membraneTab");
//     if(data.length == 0){
//         document.getElementById("chart-panel").style.display = "none";
//         document.getElementById("no-data-panel2").style.display = "block";
//     }
// }

$("#export_chart_data").click(function(){
    var name = $('#2dInput').val()
    name = name.split(";");
    var res = exportToCsv(name[1].trim() + "_FE_profile.csv", export_data);
    
    if(res === false)
    {
        alert("No chart data selected.");
    }
    
});