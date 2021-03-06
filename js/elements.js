export class VeloxElement extends HTMLElement {
    constructor(){
	super();
    }
}
export class VeloxFilterSetElement extends VeloxElement {
    constructor(){
	super();
    }
}
export class VeloxFilterElement extends VeloxElement {
    constructor(){
	super();
    }
}
export class VeloxContainerElement extends VeloxElement {
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
export class VeloxCardElement extends VeloxContainerElement {
    constructor(){
	super();
    }
    
}
export class VeloxTableElement extends VeloxContainerElement {
    constructor(){
	super();
    }
}
export class VeloxColumnElement extends VeloxElement {
    constructor(){
	super();
    }
}
export class VeloxCellElement extends VeloxElement {
    constructor(){
	super();
        this.tabOrder = 0;        
    }
}
export class VeloxControl extends VeloxElement {
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
export class VeloxTextElement extends VeloxControl {
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
export class VeloxSelectElement extends VeloxControl {
    constructor(){
	super();
        const elem = document.createElement("select");
    }
}
export class VeloxFieldsetElement extends VeloxControl {
    constructor(){
	super();
    }
}
export class VeloxCheckboxElement extends VeloxControl {
    constructor(){
	super();
        const elem = document.createElement("input");
        elem.type = "checkbox";
    }
}
export class VeloxRadioElement extends VeloxControl {
    constructor(){
	super();
        const elem = document.createElement("input");
        elem.type = "radio";
    }
}

//CustomElementRegistry element definitions
window.customElements.define('vx-filterset',VeloxFilterSetElement);
window.customElements.define('vx-filter',VeloxFilterElement);
window.customElements.define('vx-card',VeloxCardElement);
window.customElements.define('vx-table',VeloxTableElement);
window.customElements.define('vx-column',VeloxColumnElement);
window.customElements.define('vx-cell',VeloxCellElement);
window.customElements.define('vx-text',VeloxTextElement);
window.customElements.define('vx-select',VeloxSelectElement);
window.customElements.define('vx-fieldset',VeloxFieldsetElement);
window.customElements.define('vx-checkbox',VeloxCheckboxElement);
window.customElements.define('vx-radio',VeloxRadioElement);
