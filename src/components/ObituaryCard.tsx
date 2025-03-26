
import { useState } from "react";
import { cn } from "@/lib/utils";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import { ExternalLink, ChevronDown, ChevronUp } from "lucide-react";

interface ObituaryCardProps {
  obituary: {
    id: string;
    name: string;
    age?: number;
    dateOfBirth?: string;
    dateOfDeath: string;
    funeralHome: string;
    location: string;
    imageUrl?: string;
    description: string;
    sourceUrl: string;
  };
}

const ObituaryCard = ({ obituary }: ObituaryCardProps) => {
  const [expanded, setExpanded] = useState(false);
  const [imageLoaded, setImageLoaded] = useState(false);

  return (
    <Card 
      className={cn(
        "overflow-hidden transition-all duration-500 ease-in-out",
        "hover:shadow-md group/card border-border/40",
        expanded ? "shadow-sm" : "shadow-none"
      )}
    >
      <CardContent className="p-0">
        <div className="grid grid-cols-1 md:grid-cols-3 gap-0">
          {obituary.imageUrl && (
            <div className="relative aspect-square md:aspect-auto overflow-hidden bg-muted/20">
              <div 
                className={cn(
                  "absolute inset-0 bg-muted/50 backdrop-blur-xs",
                  imageLoaded ? "opacity-0" : "opacity-100"
                )}
              />
              <img
                src={obituary.imageUrl}
                alt={`Photo of ${obituary.name}`}
                className={cn(
                  "h-full w-full object-cover transition-all duration-700",
                  "group-hover/card:scale-[1.02]",
                  imageLoaded ? "opacity-100" : "opacity-0"
                )}
                onLoad={() => setImageLoaded(true)}
              />
            </div>
          )}
          
          <div className={cn(
            "p-6 flex flex-col space-y-4",
            obituary.imageUrl ? "md:col-span-2" : "md:col-span-3"
          )}>
            <div className="flex flex-col space-y-1">
              <div className="flex items-center justify-between">
                <div className="inline-flex items-center">
                  <span className="text-xs font-medium text-muted-foreground bg-muted px-2 py-0.5 rounded-full">
                    {obituary.location}
                  </span>
                </div>
                <span className="text-xs text-muted-foreground">
                  {obituary.funeralHome}
                </span>
              </div>
              
              <h3 className="text-xl md:text-2xl font-semibold mt-2">
                {obituary.name}
              </h3>
              
              <div className="flex items-center text-sm text-muted-foreground mt-1 mb-2">
                {obituary.dateOfBirth && (
                  <span>{obituary.dateOfBirth} - </span>
                )}
                <span>{obituary.dateOfDeath}</span>
                {obituary.age && (
                  <span className="ml-1">({obituary.age} years)</span>
                )}
              </div>
            </div>
            
            <Separator className="bg-border/60" />
            
            <div className={cn(
              "relative overflow-hidden transition-all duration-500",
              expanded ? "max-h-[800px]" : "max-h-24"
            )}>
              <p className="text-sm leading-relaxed">
                {obituary.description}
              </p>
              {!expanded && (
                <div className="absolute bottom-0 left-0 right-0 h-12 bg-gradient-to-t from-background to-transparent" />
              )}
            </div>
            
            <div className="flex items-center justify-between pt-2">
              <Button
                variant="ghost"
                size="sm"
                className="text-xs font-medium text-muted-foreground hover:text-foreground"
                onClick={() => setExpanded(!expanded)}
              >
                {expanded ? (
                  <>
                    <ChevronUp className="h-3.5 w-3.5 mr-1.5" />
                    Show Less
                  </>
                ) : (
                  <>
                    <ChevronDown className="h-3.5 w-3.5 mr-1.5" />
                    Read More
                  </>
                )}
              </Button>
              
              <a 
                href={obituary.sourceUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center text-xs font-medium text-primary hover:underline"
              >
                View Original
                <ExternalLink className="h-3 w-3 ml-1.5" />
              </a>
            </div>
          </div>
        </div>
      </CardContent>
    </Card>
  );
};

export default ObituaryCard;
