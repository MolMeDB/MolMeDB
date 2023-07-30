function update(SMILES = '', id) {
    var input, result, data, title, smiles;
    
  if(SMILES == ''){
    result =  document.getElementById("2dStructure");
    document.getElementById("loader").style.display = "block";
    result.style.display = "none";
    
    
    input  = $('#2dInput').val();
    data = input.split(";");
    title = data[1].trim();
    smiles = data[0].toString();
  }
  else {
    result = document.getElementById(id);
    result.style.display = "none";
    
    title = '';
    smiles = SMILES;
  }
 
  var opts = {
              style:     'cow',
              annotate:  'none',
              zoom:      '220',
              sma:       '',
              hdisp:     'bridgehead',
              showtitle: 'true',
              abbr:      'reagents'
  };

    title = title.replace(/^\|[^|]+\|\s+/, "");
    console.log(generate(opts, smiles, title));
    result.appendChild(generate(opts, smiles, title));
    
    if(SMILES == ''){
        document.getElementById("loader").style.display = "none";
        result.style.display = "block";
    }
    else{    
//        document.getElementById("loader").style.display = "none";
        result.style.display = "block";
    }
}

function depict_url(opts, smiles, title, w, h) {
	var smi = encodeURIComponent(smiles);
	// okay to not encode these
	smi = smi.replace(/%3D/g, '=');
	smi = smi.replace(/%5B/g, '[');
	smi = smi.replace(/%5D/g, ']');
	smi = smi.replace(/%40/g, '@');
  smi = smi.replace('%0A', '');
  smi = smi.replace('%09', '');
	var url = 'https://molmedb.upol.cz/depict/' + opts.style + '/svg?smi=' + smi + "%20" + title;
//	if (w && h)
//	  url += '&w=' + w + '&h=' + h;
	url += '&abbr=' + opts.abbr;
	url += '&hdisp=' + opts.hdisp;
	url += '&showtitle=' + opts.showtitle;
	if (opts.sma)
	  url += '&sma=' + encodeURIComponent(opts.sma);
	if (opts.zoom)
      url += '&zoom=' + encodeURIComponent(opts.zoom/100);
    if (opts.annotate)
     url += '&annotate=' + encodeURIComponent(opts.annotate);
	return url;    
}

function generate(opts, smiles, title) {
    var isrxn  = smiles.indexOf('>') != -1;
//    var width  = isrxn ? '210' : '80';
//    var height = '50';
//    return $('<div>').addClass('chemdiv')            
//                                 .append($('<img>').addClass('chemimg')
//                                                   .addClass(isrxn ? 'chemrxn' : 'chemmol')
//                                                   .attr('src', depict_url(opts, smiles, title)));
    var result = document.createElement("div");
    result.setAttribute("class", "chemdiv");
    var img = document.createElement("img");
    img.setAttribute("class", "chemimg chemmol");
    var src = depict_url(opts, smiles, title);
    img.setAttribute("src", src);
    result.appendChild(img);
    return result;
}