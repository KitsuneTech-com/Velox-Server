// AJAX -- an object class encapsulating AJAX methodology

/* This class defines an object which makes HTTP requests and makes the response available in any
	of several common formats. To use this, define a new instance of AJAX and set the properties as
	described in the function declaration, then use the send() method to send the HTTP request.
	When a successful response is received, it is parsed according to the specified response type
	and made available through the returnData property, and, if provided, to a specified callbck
	function. (Note: if the request is sent asynchronously, the response may not be available
	immediately, and therefore a callback function should be used.)
	
	This object can be reused as necessary, and the properties can be either changed or left alone
	between calls; this makes it possible to perform polling by way of setInterval() or through
	event listeners.
	
	In addition to the properties specified in the constructor, a getter property called asyncResponse
	is defined. When referenced, the send() method is invoked, and a Promise is returned that can
	be used with async/await to pause execution of the code in question until the response is ready.
	The resolved Promise returns the contents of the returnData property.

*/

export class AJAX {
    //Properties
    constructor(){
        this.target = "";           //The location of the resource (required)
        this.requestType = "GET";   //The type of request (either "GET" or "POST")
		
        this.sendData = {};         //must be in the form of an object with properties representing
                                    //the name/value pairs to be sent
                                    //e.g. {name: "value"}
                                    //Alternatively, a FormData object can be used.
												
        this.responseType = 0;      //Sets the desired response type.
                                    //Possible values:
                                    //  0: plaintext
                                    //  1: XHTML (must be well-formed)
                                    //  2: XML
                                    //  3: JSON
                                    //  4: SVG
                                    //
                                    //If any other values are specified, the response will default to plaintext.
												
        this.async = true;          //whether the HTTP request is to be sent asynchronously
		
        this.callback = null;       //optional callback function to be called when response arrives
                                    //(this will be passed the contents of this.returnData as a single argument)
		
        this.returnData = null;     //The property that will hold the response
		
		
	//Send method (defined as executor for Promise)
        this.send = (resolve, reject) => {
            const queryStr = this.sendData instanceof FormData ? queryStr : new URLSearchParams(this.sendData).toString();
			
            const request = new XMLHttpRequest();
            request.onreadystatechange = () => {
		if (request.readyState === 4){
		    if (request.status >= 200 && request.status < 300) {
			this.returnData = parseResponse(request.responseText,this.responseType);
			if (typeof resolve === "function") resolve(this.returnData);
			if (this.callback) this.callback(this.returnData);
		    }
		    else if (typeof reject === "function") {
			reject(request.statusText);
		    }
		    else {
			throw new Error(request.statusText);
		    }
		}
	    };
			
	    switch (this.requestType){
		case "GET":
		    request.open("GET", this.target + (queryStr.length > 0 ? "?" + queryStr : ""), this.async);
		    request.send();
		    break;
		case "POST":
		    request.open("POST", this.target, this.async);
		    request.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
		    request.send(queryStr);
		    break;
		default:
		    throw new Error("AJAX.requestType must be either GET or POST");
	    }
	};
    };
	
    //Getter property
    get asyncResponse(){
	return new Promise(this.send);
    }
}

//helper function for AJAX
function parseResponse(responseStr, responseType){
    let responseObj;
    const parser = new DOMParser();
	
    switch (responseType){
        case 1:     //HTML
            responseObj = parser.parseFromString(responseStr,"text/html");
            break;
        case 2:     //XML
            responseObj = parser.parseFromString(responseStr,"text/xml");
            break;
        case 3:     //JSON
            responseObj = JSON.parse(responseStr);
            break;
        case 4:     //SVG
            responseObj = parser.parseFromString(responseStr,"image/svg+xml").documentElement;
            break;
        default:    //plaintext
            responseObj = responseStr;		
    }
    return responseObj;
}

export function ajaxSingleRequest(target, sendData, requestType, responseType, callback){
    if (!target) throw "Target must be specified.";
    if (!sendData) sendData = {};
    if (!requestType) requestType = "GET";
    if (!responseType) responseType = 0;
    const request = new AJAX;
    request.target = target;
    request.sendData = sendData;
    request.requestType = requestType;
    request.responseType = responseType;
    if (callback){
        request.callback = callback;
        request.async = true;
    }
    else {
        request.async = false;	
    }
    request.send();
    return request.returnData;
}