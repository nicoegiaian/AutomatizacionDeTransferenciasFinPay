<?php
session_start();
include("includes/constants.php");
include("header.php");
set_time_limit(0);
?>  
<script>
$(document).ready(function(){
    $(".dropdown").hover(            
        function() {
            $('.dropdown-menu', this).not('.in .dropdown-menu').stop(true,true).slideDown("400");
            $(this).toggleClass('open');        
        },
        function() {
            $('.dropdown-menu', this).not('.in .dropdown-menu').stop(true,true).slideUp("400");
            $(this).toggleClass('open');       
        }
    );
	
	<?php if(!isset($_SESSION["timestamp"])) { ?>
		$.ajax({
			type: 'GET',
			headers: {
				'Accept': 'application/json',
				'Content-Type': 'application/json',
				'Access-Control-Allow-Origin': '*'
			},
			url: '<?php echo(SCRIPT_ROOT)?>api/index.php?table=pmc&method=Login&IdUsuario=<?php echo $_SESSION["IdUsuario"]?>',
			dataType: "json",
			async: false,
			success: function(data) {
				
			},
			error: function(err) {
				$("#help").html(err.responseText.replace(/["']/g, ""));
			}
		})
	<?php }?>
	
	loadDebtsDetails();
	
	$("#empresa").autocomplete({
    	minLength:3,
        delay: 100,
        source: function (request, response) {
            
            // Suggest URL
            //var suggestURL = "http://suggestqueries.google.com/complete/search?client=chrome&q=%QUERY";
            //suggestURL = suggestURL.replace('%QUERY', request.term);
            
            // JSONP Request
			$.ajax({
				type: 'POST',
				headers: {
					'Accept': 'application/json',
					'Content-Type': 'application/json',
					'Access-Control-Allow-Origin': '*'
				},
				url: '<?php echo(SCRIPT_ROOT)?>api/index.php?table=pmc&method=GetCompanies',
				dataType: "json",
				data: '{"busqueda": "'+request.term+'"}',
				success: function(data) {
					var empresas = [];
					if (data != null){
						/*[{"picture_url":"","recurrent_payment_types":[],"allowed_amounts":[],"currency_codes":[{"id":"ARS","name":"Pesos"}],"payment_methods":[{"id":"ccp","name":"Cuenta Corriente $","paymentTypeId":"bank_account","minAllowedAmount":0,"maxAllowedAmount":-1},{"id":"cap","name":"Caja de Ahorro $","paymentTypeId":"bank_account","minAllowedAmount":0,"maxAllowedAmount":-1}],"identification":{"dataLabel":"Nro. de documento","additionalDataLabel":"","minLength":0,"maxLength":0},"id":"PCOL","type":"","name":"Personal Collect","cuit":"","order":93,"read_only":true,"payment_type":3,"amount_type":0,"recharge_type":"","has_annual_billing":false,"category":{"id":"SVAR","type":"0","name":"Otros Servicios","order":19}},{"picture_url":"","recurrent_payment_types":[],"allowed_amounts":[],"currency_codes":[{"id":"ARS","name":"Pesos"}],"payment_methods":[{"id":"ccp","name":"Cuenta Corriente $","paymentTypeId":"bank_account","minAllowedAmount":2,"maxAllowedAmount":-1},{"id":"cap","name":"Caja de Ahorro $","paymentTypeId":"bank_account","minAllowedAmount":2,"maxAllowedAmount":-1}],"identification":{"dataLabel":"Codigo de pago banelco","additionalDataLabel":"","minLength":16,"maxLength":16},"id":"PERS","type":"","name":"Perseverancia Seguros","cuit":"30500032886","order":24,"read_only":false,"payment_type":3,"amount_type":0,"recharge_type":"","has_annual_billing":false,"category":{"id":"SGRS","type":"0","name":"Seguros","order":26}},{"picture_url":"","recurrent_payment_types":[],"allowed_amounts":[],"currency_codes":[{"id":"ARS","name":"Pesos"}],"payment_methods":[{"id":"ccp","name":"Cuenta Corriente $","paymentTypeId":"bank_account","minAllowedAmount":1,"maxAllowedAmount":-1},{"id":"cap","name":"Caja de Ahorro $","paymentTypeId":"bank_account","minAllowedAmount":1,"maxAllowedAmount":-1}],"identification":{"dataLabel":"Numero de ref. de pago","additionalDataLabel":"","minLength":10,"maxLength":10},"id":"PLPS","type":"T","name":"Personal","cuit":"           ","order":4,"read_only":false,"payment_type":1,"amount_type":2,"recharge_type":"","has_annual_billing":false,"category":{"id":"TLFN","type":"0","name":"Telefonia","order":32}},{"picture_url":"","recurrent_payment_types":[],"allowed_amounts":[],"currency_codes":[{"id":"ARS","name":"Pesos"}],"payment_methods":[{"id":"ccp","name":"Cuenta Corriente $","paymentTypeId":"bank_account","minAllowedAmount":2,"maxAllowedAmount":-1},{"id":"cap","name":"Caja de Ahorro $","paymentTypeId":"bank_account","minAllowedAmount":2,"maxAllowedAmount":-1}],"identification":{"dataLabel":"Codigo de producto","additionalDataLabel":"","minLength":0,"maxLength":0},"id":"QBUF","type":"","name":"Personal Collect","cuit":"","order":8,"read_only":false,"payment_type":3,"amount_type":0,"recharge_type":"","has_annual_billing":false,"category":{"id":"SVAR","type":"0","name":"Otros Servicios","order":19}},{"picture_url":"\/img\/recargas\/celular\/REPE.jpg","recurrent_payment_types":[],"allowed_amounts":[1000,1500,2000,2500,3000],"currency_codes":[{"id":"ARS","name":"Pesos"}],"payment_methods":[{"id":"ccp","name":"Cuenta Corriente $","paymentTypeId":"bank_account","minAllowedAmount":1000,"maxAllowedAmount":3000},{"id":"cap","name":"Caja de Ahorro $","paymentTypeId":"bank_account","minAllowedAmount":1000,"maxAllowedAmount":3000},{"id":"tdv","name":"Tarjeta de D\u00e9bito Visa","paymentTypeId":"debit_card","minAllowedAmount":1000,"maxAllowedAmount":3000}],"identification":{"dataLabel":"Codarea(sin 0)+nro(sin15)","additionalDataLabel":"","minLength":0,"maxLength":0,"help":"Para realizar esta operaci\u00f3n, deber\u00e1 ingresar su n\u00famero de celular, incluyendo el c\u00f3digo de \u00e1rea sin el cero (por ejemplo: 11) y el n\u00famero telef\u00f3nico sin el 15 (por ejemplo: 51234567). Luego deber\u00e1 seleccionar el importe que desea cargar. "},"id":"REPE","type":"P","name":"Personal","cuit":"           ","order":1,"read_only":false,"payment_type":1,"amount_type":2,"recharge_type":"CELULAR","has_annual_billing":false,"category":{"id":"RCEL","type":"0","name":"Recargas","order":24}},{"picture_url":"","recurrent_payment_types":[],"allowed_amounts":[],"currency_codes":[{"id":"ARS","name":"Pesos"}],"payment_methods":[{"id":"ccp","name":"Cuenta Corriente $","paymentTypeId":"bank_account","minAllowedAmount":2,"maxAllowedAmount":-1},{"id":"cap","name":"Caja de Ahorro $","paymentTypeId":"bank_account","minAllowedAmount":2,"maxAllowedAmount":-1}],"identification":{"dataLabel":"Numero de ref. de pago","additionalDataLabel":"","minLength":0,"maxLength":0},"id":"UNEV","type":"T","name":"Personalflow telecom 4p","cuit":"","order":50,"read_only":false,"payment_type":1,"amount_type":2,"recharge_type":"","has_annual_billing":false,"category":{"id":"TLFN","type":"0","name":"Telefonia","order":32}}]
*/
						
						for (var i = 0; i < data.length; i++) {
							var empresa = {label: data[i]["name"], value: data[i]["id"] + "#" + data[i]["identification"]["dataLabel"] + "#" + data[i]["identification"]["help"] + "#" + data[i]["category"]["id"] + "#" + data[i]["payment_type"] + "#" + data[i]["type"] + "#" + data[i]["name"] + "#" + data[i]["allowed_amounts"]};
							empresas.push(empresa);
						}
					}
					response(empresas);
				},
				error: function(err) {
					alert(err);
				}
			})
			
            /*$.ajax({
                method: 'GET',
                dataType: 'jsonp',
                jsonpCallback: 'jsonCallback',
                url: suggestURL
            })
            .success(function(data){
                response(data[1]);
            });*/
        },
		select: function( event, ui ) {
			var aId = ui.item.value.split("#");
			$("#idEmpresa").val(ui.item.value);
			
			ui.item.value = ui.item.label;
			if (aId[1]!="")
				$("#textoClavePagoElectronico").html(aId[1]);
			else
				$("#textoClavePagoElectronico").html("Clave pago electrónico");
			
			if (aId[2]!="undefined") 
				$("#help").html(aId[2]);
			else
				$("#help").html("");
		},
    });
	
	/*$.ajax({
		type: 'POST',
		headers: {
			'Accept': 'application/json',
			'Content-Type': 'application/json',
			'Access-Control-Allow-Origin': '*'
		},
		url: '<?php echo(SCRIPT_ROOT)?>api/index.php?table=pmc&method=GetCompanies',
		dataType: "json",
		data: '{"busqueda": "met"}',
		success: function(data) {
			for (var i = 0; i < data.length; i++) {
				$("#empresa").append('<option value="'+data[i]["id"]+'">'+data[i]["name"]+'</option>');	
			}
			
			console.log(data);
		},
		error: function(err) {
			alert(err);
		}
	})*/
});

function loadDebtsDetails(){
		$.ajax({
			type: 'GET',
			headers: {
				'Accept': 'application/json',
				'Content-Type': 'application/json',
				'Access-Control-Allow-Origin': '*'
			},
			url: '<?php echo(SCRIPT_ROOT)?>api/index.php?table=pmc&method=DebtsDetails&IdUsuario=<?php echo $_SESSION["IdUsuario"]?>',
			dataType: "json",
			success: function(data) {
				arrayVencimientos = {"conVencimientos": 1, "sinVencimientos": 2};
				
				for (var key in arrayVencimientos) {
					var tableHead = $('#' + key + ' thead');
					var tableBody = $('#' + key + ' tbody');
					tableHead.empty();
					tableBody.empty();
					$('#' + key).show();
						
					$("#help").html("");
						
					var debts = "";
					debts = JSON.parse(data);
					
					if (arrayVencimientos[key]==1) // Con vencimientos
						debts = debts.debts;
					else // Sin vencimientos
						debts = debts.accessions;
					
					//console.log(debts);
					
					tableHead.append('<th class="text-center" style="background-color:#32A1DA; padding:10px;">Empresa</th>');
					tableHead.append('<th class="text-center" style="background-color:#32A1DA; padding:10px;">Identificación</th>');
					if (arrayVencimientos[key]==1) tableHead.append('<th class="text-center" style="background-color:#32A1DA; padding:10px;">Factura</th>'); // Con vencimientos
					tableHead.append('<th class="text-center" style="background-color:#32A1DA; padding:10px;">Monto</th>');
					if (arrayVencimientos[key]==1) tableHead.append('<th class="text-center" style="background-color:#32A1DA; padding:10px;">Vencimiento</th>'); // Con vencimientos
					tableHead.append('<th class="text-center" style="background-color:#32A1DA; padding:10px;">&nbsp;</th>');
					
					for (var i = 0; i < debts.length; i++) {
						var date, day, month, year, dueDate, amount;
						dueDate = "";
						
						tableBody.append('<tr>');
						
						date = new Date(debts[i]["due_date"]);
						day = date.getDate();
						month = date.getMonth() + 1;
						year = date.getFullYear();
								
						if(month < 10) month = "0" + month;
						if(day < 10) day = "0" + day;
								
						dueDate = `${day}-${month}-${year}`;
							
						if (arrayVencimientos[key]==1){ // Con vencimientos
							tableBody.append('<td style="text-align:center;">'+debts[i]["company"]["name"]+'</td>');
							if (debts[i]["company"]["category"]["id"]=="TCIN")
								tableBody.append('<td style="text-align:center;">XXXX-XXXX-XXXX-'+debts[i]["client_id"].slice(-4)+'</td>');
							else
								tableBody.append('<td style="text-align:center;">'+debts[i]["client_id"]+'</td>');
							tableBody.append('<td style="text-align:center;">'+debts[i]["invoice_id"]+'</td>');
								
							if (debts[i]["company"]["allowed_amount"]==0){
								tableBody.append('<td style="text-align:center;">'+debts[i]["currency"]+' '+debts[i]["amount"].toLocaleString('es-AR')+'</td>');
								amount = debts[i]["amount"].toLocaleString('es-AR');
							}
							else{
								tableBody.append('<td style="text-align:center;">'+debts[i]["currency"]+'&nbsp;&nbsp;&nbsp;<input name="amount_'+debts[i]["client_id"]+'_'+debts[i]["invoice_id"].replace(/ /g, "").replaceAll(" ","")+'" type="text" class="form_input_usuarios" id="amount_'+debts[i]["client_id"]+'_'+debts[i]["invoice_id"].replace(/ /g, "")+'" /></td>');
								$("#amount_"+debts[i]["client_id"]+"_"+debts[i]["invoice_id"].replace(/ /g, "")).val(debts[i]["amount"].toLocaleString('es-AR'));
									amount = 0;
							}
							tableBody.append('<td style="text-align:center;">'+dueDate+'</td>');
							invoice = debts[i]["invoice_id"].replaceAll(" ","");
							dueDate = debts[i]["due_date"];
						}
						else{ // Sin vencimientos
								
							tableBody.append('<td style="text-align:center;"><input name="name_'+debts[i]["company"]["id"]+'_'+debts[i]["client_id"]+'" type="text" class="form_input_usuarios" id="name_'+debts[i]["company"]["id"]+'_'+debts[i]["client_id"]+'" value="'+debts[i]["company"]["name"]+'"/></td>');
							if (debts[i]["company"]["category"]["id"]=="TCIN")
								tableBody.append('<td style="text-align:center;">XXXX-XXXX-XXXX-'+debts[i]["client_id"].slice(-4)+'</td>');
							else
								tableBody.append('<td style="text-align:center;">'+debts[i]["client_id"]+'</td>');
								
							tableBody.append('<td style="text-align:center;">'+debts[i]["company"]["currency"][0]["id"]+'&nbsp;&nbsp;&nbsp;<input name="amount" type="text" class="form_input_usuarios" id="amount_'+debts[i]["client_id"]+'" /></td>');
							$("#amount").val(debts[i]["amount"]);
							amount = 0;
							invoice = "";
							dueDate = "";
						}
						
						tableBody.append('<td style="text-align:center;"><a href="#" onClick="SuscribirServicio(\''+debts[i]["company"]["id"]+'\', \''+debts[i]["client_id"]+'\', \'M\', \'\')"><img src="<?php echo ''.SCRIPT_ROOT.'';?>img/Update.png" name="modificarAgenda" id="modificarAgenda" title="Modificar" width="18" height="18"/></a>&nbsp;&nbsp;&nbsp;<a href="#" onClick="SuscribirServicio(\''+debts[i]["company"]["id"]+'\', \''+debts[i]["client_id"]+'\', \'B\', \''+debts[i]["company"]["name"]+'\')"><img src="<?php echo ''.SCRIPT_ROOT.'';?>img/Delete.png" name="borrarAgenda" id="borrarAgenda" title="Borrar" width="18" height="18"/></a>&nbsp;&nbsp;&nbsp;<a href="#" onClick="GuardarNuevoPago(\''+debts[i]["company"]["id"]+'\', \''+debts[i]["client_id"]+'\', \''+amount+'\',\''+invoice+'\',\''+debts[i]["company"]["category"]["id"]+'\',\''+dueDate+'\', false)">PAGAR</a></td>');
					}
				}		
			},
			error: function(err) {
				$("#help").html(err.responseText.replace(/["']/g, ""));
			}
		})
}

function BuscarPago(){
	var empresa = $('#idEmpresa').val().split("#")[0];
	var clavePagoElectronico = $('#clavePagoElectronico').val();
	var paymentType = $('#idEmpresa').val().split("#")[4];
	var type = $('#idEmpresa').val().split("#")[5];	
	var nombreEmpresa = $('#idEmpresa').val().split("#")[6];
	var allowedAmounts = $('#idEmpresa').val().split("#")[7];
	
	if(empresa==""){alert("Debes completar la Empresa");return}
	if(clavePagoElectronico==""){alert("Debes completar la Clave pago electrónico");return}
	
	$.ajax({
		type: 'POST',
		headers: {
			'Accept': 'application/json',
			'Content-Type': 'application/json',
			'Access-Control-Allow-Origin': '*'
		},
		url: '<?php echo(SCRIPT_ROOT)?>api/index.php?table=pmc&method=InvoicesDetails&IdUsuario=<?php echo $_SESSION["IdUsuario"]?>',
		data: '{"empresa": "'+empresa+'", "clavePagoElectronico": "'+clavePagoElectronico+'", "paymentType": "'+paymentType+'", "type": "'+type+'"}',
		dataType: "json",
		beforeSend: function(){
			$('#divCrear').html('<img src="img/loading-4.gif.webp" width="90" height="59"/> Buscando el Pago');
		},
		success: function(data) {
			var tableHead = $('#pagos thead');
			var tableBody = $('#pagos tbody');
			tableHead.empty();
			tableBody.empty();
			$('#pagos').show();
				
			$("#help").html("");
				
			var invoices = "";
			if ((paymentType=="1" && type=="T") || (paymentType=="3")){
				invoices = JSON.parse(data);
				tableHead.append('<th class="text-center" style="background-color:#32A1DA; padding:10px;">Factura</th>');
				tableHead.append('<th class="text-center" style="background-color:#32A1DA; padding:10px;">Monto</th>');
				tableHead.append('<th class="text-center" style="background-color:#32A1DA; padding:10px;">Vencimiento</th>');
				tableHead.append('<th class="text-center" style="background-color:#32A1DA; padding:10px;">&nbsp;</th>');
			}
			else{
				invoices = JSON.parse(JSON.stringify(data));
				tableHead.append('<th class="text-center" style="background-color:#32A1DA; padding:10px;">Monto</th>');
				tableHead.append('<th class="text-center" style="background-color:#32A1DA; padding:10px;">&nbsp;</th>');
			}
			var category = $('#idEmpresa').val().split("#")[3];
				
			for (var i = 0; i < invoices.invoices.length; i++) {
				var date, day, month, year, dueDate, amount;
				dueDate = "";
				
				if (invoices.invoices[i]["invoice_id"] == "SU SALDO EN DOLARES") continue;
				
				tableBody.append('<tr>');
				
				if ((paymentType=="1" && type=="T") || (paymentType=="3")){
					date = new Date(invoices.invoices[i]["due_date"]);
					date.setDate(date.getDate() /*+ 1*/); // ingresa el día anterior. Se suma 1 día
					day = date.getDate();
					month = date.getMonth() + 1;
					year = date.getFullYear();
						
					if(month < 10) month = "0" + month;
					if(day < 10) day = "0" + day;
						
					dueDate = `${day}-${month}-${year}`;
					
					tableBody.append('<td style="text-align:center;">'+invoices.invoices[i]["invoice_id"]+'</td>');
					
					if (invoices.invoices[i]["company"]["allowed_amount"]==0){
						tableBody.append('<td style="text-align:center;">'+invoices.invoices[i]["currency"]+' '+invoices.invoices[i]["amount"].toLocaleString('es-AR')+'</td>');
						amount = invoices.invoices[i]["amount"].toLocaleString('es-AR');
					}
					else{
						tableBody.append('<td style="text-align:center;">'+invoices.invoices[i]["currency"]+'&nbsp;&nbsp;&nbsp;<input name="amount" type="text" class="form_input_usuarios" id="amount" /></td>');
						$("#amount").val(invoices.invoices[i]["amount"].toLocaleString('es-AR'));
						amount = 0;
					}
						
					tableBody.append('<td style="text-align:center;">'+dueDate+'</td>');
					tableBody.append('<td style="text-align:center;"><a href="#" onClick="SuscribirServicio(\''+empresa+'\', \''+clavePagoElectronico+'\', \'A\', \''+nombreEmpresa+'\')"><img src="<?php echo ''.SCRIPT_ROOT.'';?>img/Update.png" name="altaAgenda" id="altaAgenda" title="Agendar" width="18" height="18"/></a>&nbsp;&nbsp;&nbsp;<a href="#" onClick="GuardarNuevoPago(0, 0, \''+amount+'\',\''+invoices.invoices[i]["invoice_id"]+'\',\''+category+'\',\''+invoices.invoices[i]["due_date"]+'\', false, true)">PAGAR</a></td>');
				}
				else{
					if (category=="RCEL"){
						allowedAmounts = allowedAmounts.split(",");
						var selectAmounts = "";
						
						for (var j = 0; j < allowedAmounts.length; j++) {
							amount = allowedAmounts[j].toLocaleString('es-AR');
							selectAmounts = selectAmounts + '<option value="'+amount+'">'+amount+'</option>';
						}
						tableBody.append('<td style="text-align:center;">'+invoices.invoices[i]["currency"]+'&nbsp;&nbsp;&nbsp;<select name="amount" class="form_input_usuarios" id="amount">'+selectAmounts+'</select></td>');
					}
					else
						tableBody.append('<td style="text-align:center;">'+invoices.invoices[i]["currency"]+'&nbsp;&nbsp;&nbsp;<input name="amount" type="text" class="form_input_usuarios" id="amount" /></td>');
					
					tableBody.append('<td style="text-align:center;"><a href="#" onClick="SuscribirServicio(\''+empresa+'\', \''+clavePagoElectronico+'\', \'A\', \''+nombreEmpresa+'\')"><img src="<?php echo ''.SCRIPT_ROOT.'';?>img/Update.png" name="altaAgenda" id="altaAgenda" title="Agendar" width="18" height="18"/></a>&nbsp;&nbsp;&nbsp;<a href="#" onClick="GuardarNuevoPago(0, 0, 0,\''+invoices.invoices[i]["invoice_id"]+'\',\''+category+'\',\''+invoices.invoices[i]["due_date"]+'\', true, true)">PAGAR</a></td>');
				}
				
				tableBody.append('</tr>');
				
				$('#divCrear').html('<input name="Buscar" type="button" class="form_boton" id="Buscar" value="BUSCAR" onClick="BuscarPago()"/>');
			}
		},
		error: function(err) {
			var error = err.responseText.replace(/["']/g, "");
			if(paymentType=="1" && type=="T" && error.search("47") > 0){
				var tableHead = $('#pagos thead');
				var tableBody = $('#pagos tbody');
				tableHead.empty();
				tableBody.empty();
				$('#pagos').show();
					
				$("#help").html("");
					
				var invoices = "";
				tableHead.append('<th class="text-center" style="background-color:#32A1DA; padding:10px;">Monto</th>');
				tableHead.append('<th class="text-center" style="background-color:#32A1DA; padding:10px;">&nbsp;</th>');
				
				var category = $('#idEmpresa').val().split("#")[3];
					
				var date, day, month, year, dueDate, amount;
				dueDate = "";
					
				tableBody.append('<tr>');
					
				tableBody.append('<td style="text-align:center;">ARS&nbsp;&nbsp;&nbsp;<input name="amount" type="text" class="form_input_usuarios" id="amount" /></td>');
				tableBody.append('<td style="text-align:center;"><a href="#" onClick="SuscribirServicio(\''+empresa+'\', \''+clavePagoElectronico+'\', \'A\', \''+nombreEmpresa+'\')"><img src="<?php echo ''.SCRIPT_ROOT.'';?>img/Update.png" name="altaAgenda" id="altaAgenda" title="Agendar" width="18" height="18"/></a>&nbsp;&nbsp;&nbsp;<a href="#" onClick="GuardarNuevoPago(0, 0, 0,\'\',\''+category+'\',\''+dueDate+'\', true, true)">PAGAR</a></td>');
					
				tableBody.append('</tr>');
				
				$('#divCrear').html('<input name="Buscar" type="button" class="form_boton" id="Buscar" value="BUSCAR" onClick="BuscarPago()"/>');
			}
			else{
				$('#divCrear').html('<input name="Buscar" type="button" class="form_boton" id="Buscar" value="BUSCAR" onClick="BuscarPago()"/>');
				$("#help").html(err.responseText.replace(/["']/g, ""));
			}
		}
	})
}

function GuardarNuevoPago(empresa, clavePagoElectronico, monto, factura, categoria, fechaVencimiento, agregadoManualmente, suscribir){
	
	if (suscribir){
		empresa = $('#idEmpresa').val().split("#")[0];
		clavePagoElectronico = $('#clavePagoElectronico').val();	
	}
	
	/*console.log(suscribir);
	console.log(monto);
	console.log($("#amount").val());*/
	
	if (monto==0){
		if (suscribir){
			monto = $("#amount").val();	
		}
		else{
			if (factura=="")
				monto = $("#amount_" + clavePagoElectronico).val();
			else
				monto = $("#amount_" + clavePagoElectronico + "_" + factura).val();
		}	
	} 
	monto = monto.replace(".","");
	monto = monto.replace(",",".");
	
	$.ajax({
		type: 'POST',
		headers: {
		  'Accept': 'application/json',
		  'Content-Type': 'application/json',
		  'Access-Control-Allow-Origin': '*'
		},
		url: '<?php echo(SCRIPT_ROOT)?>api/index.php?table=pmc&method=AgentsPayments&IdUsuario=<?php echo $_SESSION["IdUsuario"]?>',
		data: '{"empresa": "'+empresa+'", "clavePagoElectronico": "'+clavePagoElectronico+'", "monto": "'+monto+'", "factura": "'+factura+'", "categoria": "'+categoria+'", "fechaVencimiento": "'+fechaVencimiento+'", "agregadoManualmente": '+agregadoManualmente+'}',
		dataType: "json",
		beforeSend: function(){
			$('#divCrear').html('<img src="img/loading-4.gif.webp" width="90" height="59"/> Creando el Pago');
        },
		success: function(data) {
			var payment = JSON.parse(data);
			var comprobante = payment.payment.transaction_number;
			var control = payment.payment.control_number;
			
			$("#help").html("");
			
			$.ajax({
				type: 'POST',
				headers: {
				  'Accept': 'application/json',
				  'Content-Type': 'application/json',
				  'Access-Control-Allow-Origin': '*'
				},
				url: '<?php echo(SCRIPT_ROOT)?>api/index.php?table=pagos',
				data: '{"pmc": "SI", "empresa": "'+empresa+'", "clavePagoElectronico": "'+clavePagoElectronico+'", "monto": "'+monto+'", "factura": "'+factura+'", "categoria": "'+categoria+'", "fechaVencimiento": "'+fechaVencimiento+'", "comprobante": "'+comprobante+'", "control": "'+control+'"}',
				dataType: "json",
				success: function(data) {
					if (categoria=="TCIN"){ // Tarjeta de crédito => reemplazar los números por X y dejar sólo los últimos 4
						var x = "X";
						clavePagoElectronico = x.repeat(clavePagoElectronico.length - 4) + clavePagoElectronico.slice(-4);
					}
					$('#divCrear').html('El Pago fue creado correctamente. Hacé click <a href="#" onClick=window.open("comprobante.php?empresa=' + empresa + '&clavePagoElectronico=' + clavePagoElectronico + '&factura=' + factura + '&comprobante=' + comprobante + '")>aquí</a> para descargar el comprobante.<br><br><input name="Buscar" type="button" class="form_boton" id="Buscar" value="BUSCAR" onClick="BuscarPago()"/>');
					var tableHead = $('#pagos thead');
					var tableBody = $('#pagos tbody');
					tableHead.empty();
					tableBody.empty();
					$('#pagos').show();
				},
				error: function(err) {
				  $("#help").html(err.responseText.replace(/["']/g, ""));
				  $('#divCrear').html('<input name="Buscar" type="button" class="form_boton" id="Buscar" value="BUSCAR" onClick="BuscarPago()"/>');
				}
			})
		},
		error: function(err) {
		  $("#help").html(err.responseText.replace(/["']/g, ""));
		  $('#divCrear').html('<input name="Buscar" type="button" class="form_boton" id="Buscar" value="BUSCAR" onClick="BuscarPago()"/>');
		}
	})
}

function SuscribirServicio(empresa, clavePagoElectronico, tipoSuscripcion, nombreEmpresa){
	
	if (nombreEmpresa=="") nombreEmpresa = $("#name_" + empresa + "_" + clavePagoElectronico).val();
	
	$.ajax({
		type: 'POST',
		headers: {
		  'Accept': 'application/json',
		  'Content-Type': 'application/json',
		  'Access-Control-Allow-Origin': '*'
		},
		url: '<?php echo(SCRIPT_ROOT)?>api/index.php?table=pmc&method=Subscriptions&IdUsuario=<?php echo $_SESSION["IdUsuario"]?>',
		data: '{"empresa": "'+empresa+'", "clavePagoElectronico": "'+clavePagoElectronico+'", "tipoSuscripcion": "'+tipoSuscripcion+'", "nombreEmpresa": "'+nombreEmpresa+'"}',
		dataType: "json",
		beforeSend: function(){
			switch (tipoSuscripcion) {
				case "A":
					$('#divCrear').html('<img src="img/loading-4.gif.webp" width="90" height="59"/> Agendando el Vecimiento');
					break;
				case "M":
					$('#divCrear').html('<img src="img/loading-4.gif.webp" width="90" height="59"/> Modificando el Vecimiento');
					break;
				case "B":
					$('#divCrear').html('<img src="img/loading-4.gif.webp" width="90" height="59"/> Eliminando el Vecimiento');
					break;
			}
        },
		success: function(data) {
			switch (tipoSuscripcion) {
				case "A":
					$('#divCrear').html('El Servicio fue agendado correctamente.');
					break;
				case "M":
					$('#divCrear').html('El Servicio fue modificado de la agenda de vencimientos correctamente.');
					break;
				case "B":
					$('#divCrear').html('El Servicio fue eliminado de la agenda de vencimientos correctamente.');
					break;
			}
			$('#divCrear').html('<input name="Buscar" type="button" class="form_boton" id="Buscar" value="BUSCAR" onClick="BuscarPago()"/>');
			window.location.reload();
		},
		error: function(err) {
		  $("#help").html(err.responseText.replace(/["']/g, ""));
		  $('#divCrear').html('<input name="Buscar" type="button" class="form_boton" id="Buscar" value="BUSCAR" onClick="BuscarPago()"/>');
		}
	})
}

</script>
<nav class="navbar navbar-default" style="solid medium; ">
  <div style="padding-left: 1%; width: 98%;">
  <table width="100%" border="0" cellspacing="0" cellpadding="0">
    <tr>
      <td width="25%" class="menu-img"><span style="font-size: 25px">Pagos</td>
      <td class="menu-desk">&nbsp;</td>
      <td width="75%" class="menu-desk">&nbsp;</td>
    </tr>
  </table>
</div>
</nav>

<nav style="border-bottom:#d9d9d9 solid medium; background-color: #d9d9d9;">
 
  <div class="row " style="padding-top:5px; padding-bottom:5px; ">

  <div style="padding-left: 1%; width: 98%;">
  
</div>
</div>
</nav>
	
<nav class="navbar navbar-default" style="solid medium; ">
  <div style="padding-left: 3%; width: 98%;">
	<table width="100%" border="0" cellspacing="0" cellpadding="0">
    <tr>
      <td>
		<form id="form" method="post" action="">
    		<div class="col-sm-6 col-md-6 col-lg-6 col-xs-12 textos" style="padding:10px;" >
      			<table id="tblUsuario" style="width:100%" align="center" cellspacing="0" cellpadding="3" >
					<thead id="tblheadUsuario"></thead>
					<tbody id="tbldataUsuario">
						<tr>
							<td align="center" style="padding:10px;">Empresa</td>
							<td align="center">
								<input class="form_select_usuarios" name="empresa" id="empresa" style="font-family: Oswald, sans-serif;">
								<input type="hidden" class="form_select_usuarios" name="idEmpresa" id="idEmpresa" style="font-family: Oswald, sans-serif;"></td>
						</tr>
						<tr>
							<td align="center" style="padding:10px;"><div id="textoClavePagoElectronico">Clave pago electrónico</div></td>
							<td align="center"><input name="clavePagoElectronico" type="text" class="form_input_medium" id="clavePagoElectronico" /></td>
						</tr>
						<tr>
							<td align="center" colspan="2">&nbsp;</td>
						</tr>
						<tr>
							<td align="center" colspan="2"><div id="help" style="font-size: 10px;"></div></td>
						</tr>
						<tr>
							<td align="center" colspan="2"><div id="divCrear"><input name="Buscar" type="button" class="form_boton" id="Buscar" value="BUSCAR" onClick="BuscarPago()"/></div></td>
						</tr>
						<tr>
							<td align="center" colspan="2">&nbsp;</td>
						</tr>
						<tr>
							<td align="center" colspan="2">&nbsp;</td>
						</tr>
						<tr>
							<td align="center" colspan="2"><a href="http://wa.me/5491135112626?text=Hola, mi nombre es <?php echo $_SESSION["Nombre"] ?> y tengo problemas para pagar un servicio.">Tengo problemas para pagar un servicio</a> - <a href="buscarComprobantes.php">Buscar Comprobantes</a></td>
						</tr>
						<tr>
							<td align="center" colspan="2">&nbsp;</td>
						</tr>
						<tr>
							<td align="center" colspan="2">
								<table width="100%" border="0" cellspacing="0" cellpadding="0" id="pagos">
									<thead id="tblhead"></thead>
									<tbody id="tbldata"></tbody>
								</table>
							</td>
						</tr>
						<tr>
							<td align="center" colspan="2">&nbsp;</td>
						</tr>
						<tr>
							<td align="center" colspan="2">
								<b>Pagos con vencimiento</b><br>
								<table width="100%" border="0" cellspacing="0" cellpadding="0" id="conVencimientos">
									<thead id="tblhead"></thead>
									<tbody id="tbldata"></tbody>
								</table>
							</td>
						</tr>
						<tr>
							<td align="center" colspan="2">&nbsp;</td>
						</tr>
						<tr>
							<td align="center" colspan="2">
								<b>Pagos sin vencimiento</b><br>
								<table width="100%" border="0" cellspacing="0" cellpadding="0" id="sinVencimientos">
									<thead id="tblhead"></thead>
									<tbody id="tbldata"></tbody>
								</table>
							</td>
						</tr>
						<tr>
							<td align="center" colspan="2">&nbsp;</td>
						</tr>
					</tbody>
				</table>
    			</div>
     		</form></td>
    </tr>
  </table>
  </div>
</nav>
	
<?php 
include("footer.php");
?>