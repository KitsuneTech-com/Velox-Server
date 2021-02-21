class VeloxElement extends HTMLElement {
    constructor(){
	super();
    }
}
class VeloxFilterSetElement extends VeloxElement {
    constructor(){
	super();
    }
}
class VeloxFilterElement extends VeloxElement {
    constructor(){
	super();
    }
}
class VeloxContainerElement extends VeloxElement {
    constructor(){
        super();
        this.veloxDataObject = null;
    }
    connectedCallback(){
        if (this.getAttribute("query")){
            try {
                this.veloxDataObject = new VeloxData(this.getAttribute("query"),this.generate);
                
            }
            catch(ex){
                throw new Error("Unable to populate data. Details: "+ ex.message);
            }
        }
    }
    generate(){
        
    }
}
class VeloxCardElement extends VeloxContainerElement {
    constructor(){
	super();
    }
    
}
class VeloxTableElement extends VeloxContainerElement {
    constructor(){
	super();
    }
}
class VeloxColumnElement extends VeloxElement {
    constructor(){
	super();
    }
}
class VeloxCellElement extends VeloxElement {
    constructor(){
	super();
        this.tabOrder = 0;        
    }
}
class VeloxControl extends VeloxElement {
    static formAssociated = true;
    constructor(){
	super();
        this._internals = this.attachInternals();
        this._value = this.getAttribute("value");
	this.attachShadow({mode: open});
    }
    get name(){
        return this.getAttribute("name");
    }
    set name(name){
        this.setAttribute("name",name);
    }
    get value(){
        return this._value;
    }
    set value(value){
        this.setAttribute("value",value);
        this._internals.setFormValue(value);
    }
    get validity(){
        return this.internals_.validity;
    }
    get validationMessage(){
        return this.internals_.validationMessage;
    }
    get willValidate(){
        return this.internals_.willValidate;
    }

    checkValidity(){
        return this.internals_.checkValidity();
    }
    reportValidity(){
        return this.internals_.reportValidity();
    }
}
class VeloxTextElement extends VeloxControl {
    constructor(){
	super();
        this._control = document.createElement("input");
        this._control.setAttribute("type",this.getAttribute("type"));
        this._span = document.createElement("span");
        this._span.appendChild(document.createElement("slot"));
        
        //TODO - attach appropriate element to shadow root (with focus/blur listeners
        //to substitute the control if needed); make sure that the control and span
        //are of equal size to avoid layout shift
        
    }
    get type(){
        return this.getAttribute("type");
    }
    set type(type){
        this.setAttribute("type",type);    
    }
    get value(){
        
    }
    set value(value){
        this._span.innerHTML = value;
        this._control.value = value;
    }
}
class VeloxSelectElement extends VeloxControl {
    constructor(){
	super();
        const elem = document.createElement("select");
    }
}
class VeloxFieldsetElement extends VeloxControl {
    constructor(){
	super();
    }
}
class VeloxCheckboxElement extends VeloxControl {
    constructor(){
	super();
        const elem = document.createElement("input");
        elem.type = "checkbox";
    }
}
class VeloxRadioElement extends VeloxControl {
    constructor(){
	super();
        const elem = document.createElement("input");
        elem.type = "radio";
    }
}
