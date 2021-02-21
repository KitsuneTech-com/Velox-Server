import {AJAX} from "./ajax.js";
export class VeloxData {
    constructor(query, callback){
	if (!callback){
	    callback = function(){}; //use empty function if no callback is provided
	}
	this.connection = new AJAX;
	this.connection.target = "/velox?q=" + query;
	this.connection.responseType = 3;
	this.connection.callback = this.updateModel;
	this.connection.sendData = {
	    select: [],
	    update: [],
	    insert: [],
	    delete: [],
	    transaction: []
	};
	this.model = [];
	this.callback = function(data){
	    if (data.Exception){
		throw new Error('An error occurred on the server.\n\nDetails:\n' + JSON.stringify(data.Exception));
	    }
	    callback(data);
	};
	this.activeTransaction = false;
    }
    updateModel(data){
	this.model = data;
	this.callback(data);
    }
    sendRequest(){
	this.connection.send();
	this.activeTransaction = false;
    }
    startTransaction(){
	this.activeTransaction = true;
    }
    select(criteria){
	this.connection.sendData.select.push(criteria);
	if (!this.activeTransaction){
	    this.sendRequest();
	}
    }
    update(rows){
	this.connection.sendData.update.push(rows);
    }
    insert(rows){
	this.connection.sendData.insert.push(rows);
    }
    delete(rows){
	this.connection.sendData.delete.push(rows);
    }
}