var config;
var data;
var res = [];
var dataset = [];
var export_data = [];

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


function loadLineChart(id){
    var labelsSet = [];
    var color = ['rgba(50,230,21,1)', 'rgba(255,99,132,1)', 'rgba(80,99,210,1)', 'rgba(255,220,100,1)', 'rgba(0,50,255,1)' , 'rgba(229,146,0,1)', 'rgba(150,0,255,1)', 'rgba(110,200,132,1)', 
                 'rgba(215,99,255,1)', 'rgba(25,99,160,1)', 'rgba(255,150,0,1)', 'rgba(0,255,132,1)', 'rgba(100,150,0,1)', 'rgba(20,130,250,1)'];
    var data = document.querySelectorAll("div.tabBlock");
    var size = data.length;
    var mem = [];
    var met = [];
    var count = 0;
    export_data = [];
    export_data[0] = [];
    export_data[0][0] = 'Distance [nm]';
    
    for (var i = 0; i<size; i++){
        for(var j = 0; j<data[i].children.length; j++){
            if(data[i].children[j].className === "active"){
                var t = data[i].children[j].childNodes[1].value;
                t = t.split(";");
                met[count] = t[0];
                mem[count] = t[1];
                count++;
            }
        }
    }

    for(var i=0; i<count; i++){
        var ajax_data = ajax_request('energy/all', { id_compound: id, id_membrane: mem[i], id_method: met[i], limit: 0});
        res[i] = ajax_data;

        if(!ajax_data)
        {
            res[i] = [{
                energy: 0,
                distance: 0
            }]
        }
    }
    
    for(var i=0; i<36; i++)
    {
        labelsSet[i] = i/10;
        export_data[i+1] = [];
        export_data[i+1][0] = i/10;
    }
    
    dataset = [];
    
    for(var i=0; i<count; i++)
    {
        var membrane_detail = ajax_request('membrane/get', {id: mem[i]});
        var method_detail = ajax_request('method/get', {id: met[i]});
        var label = {
            membrane: membrane_detail ? membrane_detail.CAM : null,
            method: method_detail ? method_detail.CAM : null
        };
        
        if(!label.membrane)
        {
            label.membrane = mem[i];
        }
        if(!label.method)
        {
            label.method = met[i];
        }

        dataset[i] = {};
        dataset[i].label = label.method + "(" + label.membrane + ")";
        dataset[i].borderWidth = 1;
        dataset[i].pointRadius = 4;
        dataset[i].backgroundColor = 'rgba(255,255,255,0.1)';
        dataset[i].borderColor = color[i].toString();
        export_data[0][i+1] = dataset[i].label + " [kcal/mol]";
        
        var index; var x_main = []; var y_main = []; var X = []; var Y = []; var values = [];
        
        var size = res[i].length;
        for(var j=0; j<size; j++){ //Copying values of free energy profile to the arrays
            x_main[j] = res[i][j].distance;
            y_main[j] = res[i][j].energy;
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